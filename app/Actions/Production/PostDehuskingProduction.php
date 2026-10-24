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
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeOutput;
use App\Services\Production\ProductionCostAllocator;
use App\Services\Production\ProductionLots;
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
 * COSTING (recipe version 2): the dehusked coconut carries 100% of the consumed coconut
 * value, and the raw husk carries none of it. The husk's cost is the run's LABOUR instead:
 * quantity x the per-coconut rate confirmed on the draft. Which output carries labour is the
 * recipe's `absorbs_labour` flag, never a product code.
 *
 * Two inventory transactions are written, because the ledger records one transaction type
 * per transaction and production involves two:
 *
 *   PRODUCTION_CONSUMPTION — whole coconut OUT, valued by the engine at the weighted
 *                            average in force
 *   PRODUCTION_OUTPUT      — dehusked coconut and raw husk IN, each carrying its
 *                            configured share of the consumed value, plus the labour on
 *                            the output that absorbs it
 *
 * Both are written inside ONE database transaction, so the pair is atomic: a failure
 * anywhere leaves no consumed stock, no output stock, no lots and no posted order.
 *
 * VALUE IS CONSERVED: consumed value + labour = output value, exactly. The allocated shares
 * sum exactly to the consumed value (the final output absorbs any rounding remainder) and
 * the labour is added on top, so the books show where every rupee came from:
 * Dr output inventory / Cr input inventory, Cr production labour absorbed.
 *
 * No electricity, machine or overhead cost is added; that is a later costing phase.
 * Lots follow each product's `tracks_lots` flag (ProductionLots).
 *
 * See docs/BUSINESS_RULES.md §18.
 */
class PostDehuskingProduction
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

            // 3. Derive output quantities and share the consumed value across them, then add
            //    the labour to the output that carries it.
            $labourCost = $this->labourCost($locked, $recipe, DecimalMath::of($input->quantity));
            $allocations = $this->allocate($recipe, DecimalMath::of($input->quantity), $consumedValue, $labourCost);

            $outputLines = [];
            $outputRecords = [];

            foreach ($allocations as $allocation) {
                $lot = $this->lots->outputLot($allocation['product'], $locked, $input);

                $outputLines[] = (new PendingInventoryLine(
                    productId: $allocation['product']->id,
                    warehouseId: $locked->warehouse_id,
                    direction: InventoryDirection::In,
                    quantity: $allocation['quantity'],
                    lotId: $lot?->id,
                ))
                    // The allocator's exact allocated cost reaches the ledger unchanged, so
                    // the outputs sum to the consumed value to the last decimal even when
                    // the per-unit rate repeats.
                    ->withAuthoritativeCost($allocation['unit_cost'], $allocation['allocated_cost']);

                $outputRecords[] = [
                    'product_id' => $allocation['product']->id,
                    'lot_id' => $lot?->id,
                    'quantity' => $allocation['quantity'],
                    'allocation_percent' => $allocation['percent'],
                    'allocated_cost' => $allocation['allocated_cost'],
                    'labour_cost' => $allocation['labour_cost'],
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
                'production_recipe_id' => $recipe->getKey(),
                'labour_cost' => $labourCost,
                'inventory_transaction_id' => $consumption->getKey(),
                'output_inventory_transaction_id' => $outputTransaction->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            // Dr output inventory, Cr input inventory and Cr production labour absorbed — at
            // the exact figures the ledger holds. Accounting never recalculates the allocation
            // percentages or the labour; it records what they already produced.
            $this->accounting->forProduction($locked, $consumption, $outputTransaction, $userId);

            return $locked->refresh()->load(['inputs', 'outputs']);
        });
    }

    /**
     * The run's labour: quantity processed x the rate confirmed on the draft.
     *
     * Only a recipe with an output that absorbs labour has any; the rate is then required,
     * because a missing rate would silently give that output no cost at all. Zero is a
     * deliberate, accepted answer.
     */
    private function labourCost(ProductionOrder $order, ProductionRecipe $recipe, string $inputQuantity): ?string
    {
        if ($recipe->labourOutput() === null) {
            return null;
        }

        if ($order->labour_rate === null) {
            throw ValidationException::withMessages([
                'labour_rate' => __('Enter the dehusking labour rate per coconut before posting.'),
            ]);
        }

        $rate = DecimalMath::of($order->labour_rate);

        if (DecimalMath::isNegative($rate)) {
            throw ValidationException::withMessages([
                'labour_rate' => __('The labour rate cannot be negative.'),
            ]);
        }

        return DecimalMath::cost(DecimalMath::mul($inputQuantity, $rate));
    }

    /**
     * Derive each output's quantity from the recipe ratio, then hand the whole set to the
     * shared allocator, which shares the consumed value and guarantees the totals are
     * exact. The labour is then added, whole, to the output that absorbs it.
     *
     * The ratio is what encodes the 1:1:1 rule — it is configuration, not a constant in
     * code. Dehusking always produces every output, so every one participates.
     *
     * @return list<array<string, mixed>>
     */
    private function allocate(ProductionRecipe $recipe, string $inputQuantity, string $consumedValue, ?string $labourCost): array
    {
        $labourProductId = $recipe->labourOutput()?->product_id;

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
            function (array $allocation) use ($labourProductId, $labourCost): array {
                $carriesLabour = $labourCost !== null && $allocation['product']->id === $labourProductId;

                if (! $carriesLabour) {
                    return [
                        'product' => $allocation['product'],
                        'quantity' => $allocation['quantity'],
                        'percent' => $allocation['configured_percent'],
                        'allocated_cost' => $allocation['allocated_cost'],
                        'labour_cost' => null,
                        'unit_cost' => $allocation['unit_cost'],
                    ];
                }

                $total = DecimalMath::cost(DecimalMath::add($allocation['allocated_cost'], $labourCost));

                return [
                    'product' => $allocation['product'],
                    'quantity' => $allocation['quantity'],
                    'percent' => $allocation['configured_percent'],
                    'allocated_cost' => $total,
                    'labour_cost' => $labourCost,
                    // Quantity is derived from a positive input at a positive ratio.
                    'unit_cost' => DecimalMath::cost(DecimalMath::div($total, $allocation['quantity'])),
                ];
            },
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
        // One recipe per input product (coconut size): the draft's input says which.
        $recipe = ProductionRecipe::forType(
            $order->production_type,
            $order->inputs()->orderBy('id')->value('product_id'),
        );

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

        // A lot-tracked coconut must name its lot, so the output stays traceable; pooled
        // coconut has none to name.
        $this->lots->assertInputLot(
            $input,
            __('Select the coconut lot being processed, so the output lots remain traceable.'),
        );

        return $input;
    }
}
