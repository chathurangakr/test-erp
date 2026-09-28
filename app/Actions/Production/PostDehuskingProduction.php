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
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeOutput;
use App\Services\Production\ProductionCostAllocator;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft dehusking production order.
 *
 * Dehusking converts whole coconut into dehusked coconut and raw husk at a confirmed 1:1:1
 * ratio. Both outputs are DERIVED from the input quantity via the recipe — an operator
 * never types them.
 *
 * Two inventory transactions are written, because the ledger records one transaction type
 * per transaction and production involves two:
 *
 *   PRODUCTION_CONSUMPTION — whole coconut OUT, valued by the engine at the weighted
 *                            average in force
 *   PRODUCTION_OUTPUT      — dehusked coconut and raw husk IN, each carrying its
 *                            configured share of the consumed value
 *
 * Both are written inside ONE database transaction, so the pair is atomic: a failure
 * anywhere leaves no consumed stock, no output stock, no lots and no posted order.
 *
 * VALUE IS CONSERVED. Physical item count rises — one coconut becomes two things — but the
 * total money in inventory does not change. The allocated costs sum exactly to the consumed
 * value, with the final output absorbing any rounding remainder.
 *
 * No labour, electricity, machine or overhead cost is added; that is a later costing phase.
 *
 * See docs/BUSINESS_RULES.md §18.
 */
class PostDehuskingProduction
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
            // Re-read under lock so two concurrent posts cannot both pass the draft check.
            $locked = ProductionOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $recipe = $this->resolveRecipe($locked);
            $input = $this->resolveInput($locked, $recipe);

            // 1. Consume the input. The engine values this at the current weighted average
            //    and rejects it if stock — or the chosen lot — would go negative.
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

            // 3. Derive output quantities and share the consumed value across them.
            $allocations = $this->allocate($recipe, DecimalMath::of($input->quantity), $consumedValue);

            $outputLines = [];
            $outputRecords = [];

            foreach ($allocations as $allocation) {
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

                $outputRecords[] = [
                    'product_id' => $allocation['product']->id,
                    'lot_id' => $lot->id,
                    'quantity' => $allocation['quantity'],
                    'allocation_percent' => $allocation['percent'],
                    'allocated_cost' => $allocation['allocated_cost'],
                    'unit_cost' => $allocation['unit_cost'],
                ];
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

            foreach ($outputRecords as $record) {
                $locked->outputs()->create($record);
            }

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

    /**
     * Derive each output's quantity from the recipe ratio, then hand the whole set to the
     * shared allocator, which shares the consumed value and guarantees the totals are
     * exact.
     *
     * The ratio is what encodes the 1:1:1 rule — it is configuration, not a constant in
     * code. Dehusking always produces every output, so every one participates.
     *
     * @return list<array<string, mixed>>
     */
    private function allocate(ProductionRecipe $recipe, string $inputQuantity, string $consumedValue): array
    {
        $outputs = $recipe->outputs->values()->map(function (ProductionRecipeOutput $output) use ($inputQuantity): array {
            return [
                'product' => $output->product,
                'quantity' => DecimalMath::quantity(
                    DecimalMath::mul($inputQuantity, DecimalMath::of($output->output_ratio ?? '0'))
                ),
                'percent' => DecimalMath::of($output->allocation_percent),
            ];
        })->all();

        return array_map(
            fn (array $allocation): array => [
                'product' => $allocation['product'],
                'quantity' => $allocation['quantity'],
                'percent' => $allocation['configured_percent'],
                'allocated_cost' => $allocation['allocated_cost'],
                'unit_cost' => $allocation['unit_cost'],
            ],
            $this->allocator->allocate($outputs, $consumedValue),
        );
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

        // Dehusking writes its own output rows, because every dehusking quantity is derived
        // from the recipe and none may be supplied (decision P-4). A draft that somehow
        // already carries output lines would therefore end up with duplicates — and a
        // duplicated output would double-count the run's value. Refuse rather than merge.
        if ($order->outputs()->exists()) {
            throw ValidationException::withMessages([
                'outputs' => __('Dehusking output quantities are derived from the recipe, so a draft must not carry output lines.'),
            ]);
        }

        // Belt and braces against double posting.
        if ($order->inventory_transaction_id !== null || $order->output_inventory_transaction_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Production order :reference has already been posted.', [
                    'reference' => $order->production_number,
                ]),
            ]);
        }

        if ($order->production_type !== ProductionType::Dehusking) {
            throw ValidationException::withMessages([
                'production_type' => __('This action posts dehusking only; :type is not implemented yet.', [
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

        // A recipe that does not allocate exactly 100% would create or destroy value.
        $recipe->assertAllocationIsComplete();

        return $recipe;
    }

    private function resolveInput(ProductionOrder $order, ProductionRecipe $recipe): ProductionInput
    {
        $inputs = $order->inputs()->with(['product', 'lot'])->orderBy('id')->get();

        if ($inputs->count() !== 1) {
            throw ValidationException::withMessages([
                'inputs' => __('A dehusking order must have exactly one input line.'),
            ]);
        }

        $input = $inputs->first();

        if ($input->product_id !== $recipe->input_product_id) {
            throw ValidationException::withMessages([
                'inputs' => __('Dehusking consumes :expected, not :actual.', [
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

        // A lot is required: dehusking output must be traceable back to the coconut it came
        // from, which is only possible when the consumed lot is known.
        if ($input->lot_id === null) {
            throw ValidationException::withMessages([
                'inputs.0.lot_id' => __('Select the coconut lot being processed, so the output lots remain traceable.'),
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
}
