<?php

namespace App\Actions\Production;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\InventoryLot;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\ProductionRecipe;
use App\Services\Production\ProductionCostAllocator;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft husk chipping production order.
 *
 * Chipping differs from dehusking in one crucial way: yields genuinely vary, so there is NO
 * fixed husk-to-KG conversion. The operator measures and enters the actual KG of 7C, 9C and
 * 10C produced, and those figures are what reach inventory. Nothing here derives an output
 * weight from the input count, and the recipe records a null ratio to make that structural.
 *
 * As with dehusking, two inventory transactions are written — PRODUCTION_CONSUMPTION for
 * the raw husk and PRODUCTION_OUTPUT for the chips — inside ONE database transaction.
 *
 * Value is conserved: the consumed husk value is shared across the outputs this batch
 * actually produced, summing exactly. Waste is recorded as information only — it creates no
 * inventory line, no lot and no value, so the consumed cost is absorbed by the saleable
 * outputs.
 *
 * No labour, electricity, machine, packaging or overhead cost is added; that is a later
 * costing phase.
 *
 * See docs/BUSINESS_RULES.md §19.
 */
class PostHuskChippingProduction
{
    public function __construct(
        private readonly PostInventoryTransaction $postInventory,
        private readonly CreateInventoryLot $createLot,
        private readonly RecordOperationalJournal $accounting,
        private readonly ProductionCostAllocator $allocator,
    ) {}

    public function handle(ProductionOrder $order, ?int $userId = null): ProductionOrder
    {
        return DB::transaction(function () use ($order, $userId): ProductionOrder {
            $locked = ProductionOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $recipe = $this->resolveRecipe($locked);
            $input = $this->resolveInput($locked, $recipe);
            $outputs = $this->resolveOutputs($locked, $recipe);

            // 1. Consume the raw husk. The engine values it at the weighted average in
            //    force and rejects it if stock — or the chosen lot — would go negative.
            $consumption = $this->postInventory->handle(
                InventoryTransactionType::ProductionConsumption,
                [
                    new PendingInventoryLine(
                        productId: $input->product_id,
                        warehouseId: $locked->warehouse_id,
                        direction: InventoryDirection::Out,
                        quantity: DecimalMath::of($input->quantity),
                        lotId: $input->lot_id,
                    ),
                ],
                [
                    'transaction_date' => $locked->production_date,
                    'source_type' => ProductionOrder::class,
                    'source_id' => $locked->getKey(),
                    'notes' => RecordText::of('Production :reference input', ['reference' => $locked->production_number]),
                    'posted_by' => $userId ?? auth()->id(),
                ],
            );

            // 2. Read back what the ledger actually charged — never recompute it here.
            $consumedLine = $consumption->lines->first();
            $unitCost = DecimalMath::cost(DecimalMath::of($consumedLine->unit_cost));
            $consumedValue = DecimalMath::cost(DecimalMath::of($consumedLine->total_cost));

            $input->forceFill([
                'unit_cost' => $unitCost,
                'total_cost' => $consumedValue,
            ])->save();

            // 3. Share that value across the outputs this batch actually produced. Outputs
            //    with zero quantity take nothing, and the rest are renormalised.
            $allocations = $this->allocator->allocate(
                $outputs->map(fn (ProductionOutput $output): array => [
                    'product' => $output->product,
                    'quantity' => DecimalMath::of($output->quantity),
                    'percent' => DecimalMath::of($output->allocation_percent),
                ])->all(),
                $consumedValue,
            );

            $outputLines = [];

            foreach ($outputs->values() as $index => $output) {
                $allocation = $allocations[$index];
                $produced = DecimalMath::isPositive(DecimalMath::of($output->quantity));

                if (! $produced) {
                    // Nothing was made, so there is no stock, no lot and no value. The share
                    // is stored as the 0% it actually received, not the configured 30% — so
                    // every line satisfies allocated cost = consumed value x stored share.
                    $output->forceFill([
                        'lot_id' => null,
                        'allocation_percent' => $allocation['effective_percent'],
                        'allocated_cost' => DecimalMath::cost('0'),
                        'unit_cost' => DecimalMath::cost('0'),
                    ])->save();

                    continue;
                }

                $lot = $this->createLot->handle($allocation['product'], [
                    'source_type' => ProductionOrder::class,
                    'source_id' => $locked->getKey(),
                    'manufactured_at' => $locked->production_date,
                    'received_at' => $locked->production_date,
                    'notes' => RecordText::of('Produced by :reference from lot :lot', [
                        'reference' => $locked->production_number,
                        'lot' => $input->lot?->lot_number ?? RecordText::of('unlotted stock'),
                    ]),
                ]);

                $output->forceFill([
                    'lot_id' => $lot->id,
                    // The effective share is stored, so a batch where an output produced
                    // nothing can always be explained after the fact.
                    'allocation_percent' => $allocation['effective_percent'],
                    'allocated_cost' => $allocation['allocated_cost'],
                    'unit_cost' => $allocation['unit_cost'],
                ])->save();

                $outputLines[] = (new PendingInventoryLine(
                    productId: $allocation['product']->id,
                    warehouseId: $locked->warehouse_id,
                    direction: InventoryDirection::In,
                    quantity: $allocation['quantity'],
                    lotId: $lot->id,
                ))
                    // The allocator's exact allocated cost reaches the ledger unchanged, so
                    // the outputs sum to the consumed value to the last decimal even when
                    // the per-unit rate repeats.
                    ->withAuthoritativeCost($allocation['unit_cost'], $allocation['allocated_cost']);
            }

            $outputTransaction = $this->postInventory->handle(
                InventoryTransactionType::ProductionOutput,
                $outputLines,
                [
                    'transaction_date' => $locked->production_date,
                    'source_type' => ProductionOrder::class,
                    'source_id' => $locked->getKey(),
                    'notes' => RecordText::of('Production :reference output', ['reference' => $locked->production_number]),
                    'posted_by' => $userId ?? auth()->id(),
                ],
            );

            $locked->forceFill([
                'status' => ProductionStatus::Posted,
                'inventory_transaction_id' => $consumption->getKey(),
                'output_inventory_transaction_id' => $outputTransaction->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            // Dr output inventory, Cr input inventory — value moving between asset
            // accounts at the exact figures the ledger holds. Accounting never recalculates
            // the allocation percentages; it records what they already produced.
            $this->accounting->forProduction($locked, $consumption, $outputTransaction, $userId);

            return $locked->refresh()->load(['inputs', 'outputs']);
        });
    }

    private function assertPostable(ProductionOrder $order): void
    {
        if (! $order->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft production order can be posted. :reference is :status.', [
                    'reference' => $order->production_number,
                    'status' => $order->status->label(),
                ]),
            ]);
        }

        if ($order->inventory_transaction_id !== null || $order->output_inventory_transaction_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Production order :reference has already been posted.', [
                    'reference' => $order->production_number,
                ]),
            ]);
        }

        if ($order->production_type !== ProductionType::HuskChipping) {
            throw ValidationException::withMessages([
                'production_type' => __('This action posts husk chipping only; :type is handled elsewhere.', [
                    'type' => $order->production_type->label(),
                ]),
            ]);
        }

        if (! $order->warehouse?->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('Production cannot be posted in an inactive warehouse.'),
            ]);
        }
    }

    private function resolveRecipe(ProductionOrder $order): ProductionRecipe
    {
        $recipe = ProductionRecipe::forType($order->production_type);

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'recipe' => __('No active production recipe is configured for :type.', [
                    'type' => $order->production_type->label(),
                ]),
            ]);
        }

        // The configured shares must total 100%; otherwise a batch producing every output
        // would create or destroy value.
        $recipe->assertAllocationIsComplete();

        return $recipe;
    }

    private function resolveInput(ProductionOrder $order, ProductionRecipe $recipe): ProductionInput
    {
        $inputs = $order->inputs()->with(['product', 'lot'])->orderBy('id')->get();

        if ($inputs->count() !== 1) {
            throw ValidationException::withMessages([
                'inputs' => __('A husk chipping order must have exactly one input line.'),
            ]);
        }

        $input = $inputs->first();

        if ($input->product_id !== $recipe->input_product_id) {
            throw ValidationException::withMessages([
                'inputs' => __('Husk chipping consumes :expected, not :actual.', [
                    'expected' => $recipe->inputProduct->code,
                    'actual' => $input->product?->code ?? __('an unknown product'),
                ]),
            ]);
        }

        if (! DecimalMath::isPositive(DecimalMath::of($input->quantity))) {
            throw ValidationException::withMessages([
                'inputs.0.quantity' => __('The quantity to process must be greater than zero.'),
            ]);
        }

        // A lot is required so the chips remain traceable back to the husk they came from.
        if ($input->lot_id === null) {
            throw ValidationException::withMessages([
                'inputs.0.lot_id' => __('Select the raw husk lot being processed, so the output lots remain traceable.'),
            ]);
        }

        $lot = InventoryLot::query()->whereKey($input->lot_id)->first();

        if ($lot === null) {
            throw ValidationException::withMessages([
                'inputs.0.lot_id' => __('The selected lot does not exist.'),
            ]);
        }

        if ($lot->product_id !== $input->product_id) {
            throw ValidationException::withMessages([
                'inputs.0.lot_id' => __('Lot :lot belongs to a different product.', ['lot' => $lot->lot_number]),
            ]);
        }

        return $input;
    }

    /**
     * The operator-entered outputs, checked against the recipe.
     *
     * Quantities come from the draft exactly as measured — nothing is derived. Zero is
     * allowed for an individual grade a batch genuinely did not produce, but at least one
     * saleable output must be positive or there is nothing to carry the consumed value.
     *
     * @return Collection<int, ProductionOutput>
     */
    private function resolveOutputs(ProductionOrder $order, ProductionRecipe $recipe)
    {
        $outputs = $order->outputs()->with('product')->orderBy('id')->get();

        if ($outputs->isEmpty()) {
            throw ValidationException::withMessages([
                'outputs' => __('Enter the measured output weights before posting.'),
            ]);
        }

        $allowed = $recipe->outputs->pluck('allocation_percent', 'product_id');
        $anyPositive = false;

        foreach ($outputs as $index => $output) {
            if (! $allowed->has($output->product_id)) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.product_id" => __('Product :product is not an output of the husk chipping recipe.', [
                        'product' => $output->product?->code ?? __('unknown'),
                    ]),
                ]);
            }

            $quantity = DecimalMath::of($output->quantity);

            if (DecimalMath::isNegative($quantity)) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.quantity" => __('An output quantity cannot be negative.'),
                ]);
            }

            if (DecimalMath::isPositive($quantity)) {
                $anyPositive = true;
            }

            // Snapshot the configured share onto the line, so the allocator works from the
            // recipe rather than from whatever might be sitting on the draft.
            $output->allocation_percent = $allowed->get($output->product_id);
        }

        if (! $anyPositive) {
            throw ValidationException::withMessages([
                'outputs' => __('At least one output must have a measured quantity greater than zero.'),
            ]);
        }

        return $outputs;
    }
}
