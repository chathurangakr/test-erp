<?php

namespace App\Actions\Production;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeOutput;
use App\Services\Production\ProductionCostAllocator;
use App\Services\Production\ProductionLots;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft copra production order.
 *
 * Copra is the first run whose two outputs behave differently, and the difference is the
 * whole point of this class:
 *
 *   COPRA      KG   — MEASURED. The operator weighs the copra actually dried. Nothing
 *                     derives it from the coconut count.
 *   RAW-SHELL  NOS  — DERIVED. One shell per coconut processed, a confirmed factory rule
 *                     held as the recipe's output_ratio of 1.0. The operator cannot
 *                     override it.
 *              KG   — MEASURED. The shell is also weighed, and that weight is recorded as
 *                     the inventory SECONDARY quantity. There is no fixed NOS-to-KG
 *                     conversion for shell and there must never be one.
 *
 * Which behaviour applies to which output is read from the recipe — `derivesQuantity()` and
 * `requires_secondary_quantity` — so no product code appears here.
 *
 * As with every production type, two inventory transactions are written —
 * PRODUCTION_CONSUMPTION for the dehusked coconut and PRODUCTION_OUTPUT for the copra and
 * shell — inside ONE database transaction, and value is conserved exactly by the shared
 * ProductionCostAllocator.
 *
 * Shell is valued against its PRIMARY quantity (NOS), because that is the unit the
 * inventory engine costs it in. A cost per KG can be shown, but it is informational and is
 * never stored or used as the weighted average.
 *
 * No drying labour, fuel, electricity, machine or overhead cost is added; that is a later
 * costing phase. Moisture and process loss are not modelled at all — see
 * docs/BUSINESS_RULES.md §20.
 */
class PostCopraProduction
{
    public function __construct(
        private readonly PostInventoryTransaction $postInventory,
        private readonly ProductionLots $lots,
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
            $outputs = $this->resolveOutputs($locked, $recipe, $input);

            // 1. Consume the dehusked coconut. The engine values it at the weighted average
            //    in force and rejects it if stock — or the chosen lot — would go negative.
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

            // 3. Share that value between copra and shell, summing exactly.
            $allocations = $this->allocator->allocate(
                $outputs->map(fn (array $output): array => [
                    'product' => $output['model']->product,
                    'quantity' => DecimalMath::of($output['model']->quantity),
                    'percent' => DecimalMath::of($output['model']->allocation_percent),
                ])->all(),
                $consumedValue,
            );

            $outputLines = [];

            foreach ($outputs->values() as $index => $output) {
                $allocation = $allocations[$index];
                /** @var ProductionOutput $line */
                $line = $output['model'];

                $lot = $this->lots->outputLot($allocation['product'], $locked, $input);

                $line->forceFill([
                    'lot_id' => $lot?->id,
                    'allocation_percent' => $allocation['effective_percent'],
                    // Valued against the PRIMARY quantity: KG for copra, NOS for shell.
                    'allocated_cost' => $allocation['allocated_cost'],
                    'unit_cost' => $allocation['unit_cost'],
                ])->save();

                $outputLines[] = (new PendingInventoryLine(
                    productId: $allocation['product']->id,
                    warehouseId: $locked->warehouse_id,
                    direction: InventoryDirection::In,
                    quantity: $allocation['quantity'],
                    lotId: $lot?->id,
                    // The measured weight travels into the ledger as the secondary quantity,
                    // so stock keeps both the count and the weight actually received.
                    secondaryQuantity: $line->secondary_quantity === null
                        ? null
                        : DecimalMath::quantity(DecimalMath::of($line->secondary_quantity)),
                ))
                    // Valuation stays entirely on the PRIMARY quantity: the allocator's
                    // exact figure, against the count. The weight never enters the money.
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
                'production_recipe_id' => $recipe->getKey(),
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

        if ($order->production_type !== ProductionType::Copra) {
            throw ValidationException::withMessages([
                'production_type' => __('This action posts copra production only; :type is handled elsewhere.', [
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

        // Shares that do not total 100% would create or destroy value on every run.
        $recipe->assertAllocationIsComplete();

        return $recipe;
    }

    private function resolveInput(ProductionOrder $order, ProductionRecipe $recipe): ProductionInput
    {
        $inputs = $order->inputs()->with(['product', 'lot'])->orderBy('id')->get();

        if ($inputs->count() !== 1) {
            throw ValidationException::withMessages([
                'inputs' => __('A copra production order must have exactly one input line.'),
            ]);
        }

        $input = $inputs->first();

        if ($input->product_id !== $recipe->input_product_id) {
            throw ValidationException::withMessages([
                'inputs' => __('Copra production consumes :expected, not :actual.', [
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

        // A lot-tracked input must name its lot so the copra and shell stay traceable;
        // pooled dehusked coconut has none to name.
        $this->lots->assertInputLot(
            $input,
            __('Select the dehusked coconut lot being processed, so the output lots remain traceable.'),
        );

        return $input;
    }

    /**
     * The outputs, each checked against the way the recipe says it is quantified.
     *
     * A DERIVED output's quantity is recalculated here from the input and the recipe ratio,
     * so whatever sits on the draft cannot change what reaches stock — that is what makes
     * the shell count impossible to override. A MEASURED output keeps the operator's figure
     * exactly. An output configured to require a secondary quantity must carry a positive
     * one, because for copra the shell weight is operationally required.
     *
     * @return Collection<int, array{model: ProductionOutput, config: ProductionRecipeOutput}>
     */
    private function resolveOutputs(ProductionOrder $order, ProductionRecipe $recipe, ProductionInput $input)
    {
        $outputs = $order->outputs()->with('product')->orderBy('id')->get();
        $configured = $recipe->outputs->keyBy('product_id');

        if ($outputs->count() !== $configured->count()) {
            throw ValidationException::withMessages([
                'outputs' => __('Copra production must record every output the recipe produces.'),
            ]);
        }

        $resolved = collect();
        $seen = [];

        foreach ($outputs as $index => $output) {
            /** @var ProductionRecipeOutput|null $config */
            $config = $configured->get($output->product_id);

            if ($config === null) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.product_id" => __('Product :product is not an output of the copra recipe.', [
                        'product' => $output->product?->code ?? __('unknown'),
                    ]),
                ]);
            }

            if (in_array($output->product_id, $seen, true)) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.product_id" => __('Each output may appear only once.'),
                ]);
            }

            $seen[] = $output->product_id;

            if ($config->derivesQuantity()) {
                // Recalculated, never trusted from the draft.
                $output->quantity = DecimalMath::quantity(
                    DecimalMath::mul(DecimalMath::of($input->quantity), DecimalMath::of($config->output_ratio))
                );
            }

            $quantity = DecimalMath::of($output->quantity);

            if (! DecimalMath::isPositive($quantity)) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.quantity" => __('The measured :product quantity must be greater than zero.', [
                        'product' => $output->product?->code ?? __('output'),
                    ]),
                ]);
            }

            $this->resolveSecondaryQuantity($output, $config, $index);

            // Snapshot the configured share, so the allocator works from the recipe rather
            // than from whatever might be sitting on the draft.
            $output->allocation_percent = $config->allocation_percent;
            $output->save();

            $resolved->push(['model' => $output, 'config' => $config]);
        }

        return $resolved;
    }

    private function resolveSecondaryQuantity(ProductionOutput $output, ProductionRecipeOutput $config, int $index): void
    {
        if (! $config->requires_secondary_quantity && $output->secondary_quantity === null) {
            // Optional and not measured: no weight is kept, and none is ever derived from the
            // count. A weight that WAS entered is checked below exactly like a required one.
            return;
        }

        if ($output->product?->secondary_uom_id === null) {
            throw ValidationException::withMessages([
                "outputs.{$index}.secondary_quantity" => __('Product :product has no secondary unit of measure to record a weight against.', [
                    'product' => $output->product?->code ?? __('unknown'),
                ]),
            ]);
        }

        $secondary = $output->secondary_quantity;

        if ($secondary === null || ! DecimalMath::isPositive(DecimalMath::of($secondary))) {
            throw ValidationException::withMessages([
                "outputs.{$index}.secondary_quantity" => __('Enter the measured :product weight. It must be greater than zero.', [
                    'product' => $output->product?->code ?? __('output'),
                ]),
            ]);
        }

        $output->secondary_quantity = DecimalMath::quantity(DecimalMath::of($secondary));
    }
}
