<?php

namespace App\Actions\Production;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\ProductionRecipe;
use App\Services\Production\ProductionLots;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft husk chipping production order.
 *
 * Chipping does NOT take raw husk out of stock. The factory cannot say, run by run, how many
 * husks a batch of chips used — it knows that when the chips are sold. So the recipe defers
 * its input to delivery (`input_consumption` AT_DELIVERY): posting a run brings the measured
 * outputs into stock and nothing else, and the raw husk is BACKFLUSHED by the delivery that
 * sells them (PostDelivery), at the husk's weighted average, straight into cost of goods sold.
 *
 * The outputs therefore enter stock with their raw-material cost PENDING — at zero value.
 * Nothing of value moved, so no journal is written and the ledger is not distorted: the husk
 * stays in Raw Husk inventory at its true cost until it is backflushed.
 *
 * The operator's rows are the measured truth: any product the recipe allows, in that
 * product's own unit (KG or L — separate stock items, never converted), every row positive.
 * There is NO conversion from husks to KG or litres anywhere. Waste is information only.
 *
 * One PRODUCTION_OUTPUT transaction is written, inside one database transaction.
 *
 * See docs/BUSINESS_RULES.md §19.
 */
class PostHuskChippingProduction
{
    public function __construct(
        private readonly PostInventoryTransaction $postInventory,
        private readonly ProductionLots $lots,
        private readonly RecordOperationalJournal $accounting,
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
            $this->assertNoInput($locked);
            $outputs = $this->resolveOutputs($locked, $recipe);

            $outputLines = [];

            foreach ($outputs as $output) {
                $lot = $this->lots->outputLot($output->product, $locked, null);

                // Zero value: the raw husk's cost is pending until the delivery backflushes it.
                $output->forceFill([
                    'lot_id' => $lot?->id,
                    'allocation_percent' => DecimalMath::round('0', 6),
                    'allocated_cost' => DecimalMath::cost('0'),
                    'labour_cost' => null,
                    'unit_cost' => DecimalMath::cost('0'),
                ])->save();

                $outputLines[] = (new PendingInventoryLine(
                    productId: $output->product_id,
                    warehouseId: $locked->warehouse_id,
                    direction: InventoryDirection::In,
                    quantity: DecimalMath::quantity(DecimalMath::of($output->quantity)),
                    lotId: $lot?->id,
                ))->withAuthoritativeCost(DecimalMath::cost('0'), DecimalMath::cost('0'));
            }

            $outputTransaction = $this->postInventory->handle(
                InventoryTransactionType::ProductionOutput,
                $outputLines,
                [
                    'transaction_date' => $locked->production_date,
                    'source_type' => ProductionOrder::class,
                    'source_id' => $locked->getKey(),
                    'notes' => RecordText::of('Production :reference output (raw husk pending backflush)', [
                        'reference' => $locked->production_number,
                    ]),
                    'posted_by' => $userId ?? auth()->id(),
                ],
            );

            $locked->forceFill([
                'status' => ProductionStatus::Posted,
                'production_recipe_id' => $recipe->getKey(),
                'output_inventory_transaction_id' => $outputTransaction->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            // Still asked, so the rule lives in one place: a run that moved no value records
            // no journal.
            $this->accounting->forProduction($locked, null, $outputTransaction, $userId);

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

        $recipe->assertAllocationIsComplete();

        if (! $recipe->consumesInputAtDelivery()) {
            throw ValidationException::withMessages([
                'recipe' => __('The active husk chipping recipe must backflush its raw husk at delivery.'),
            ]);
        }

        return $recipe;
    }

    /**
     * A draft written under the old method carries a raw-husk input line. Posting it now would
     * take no husk while the operator believed it did — so it is refused, not reinterpreted.
     */
    private function assertNoInput(ProductionOrder $order): void
    {
        if ($order->inputs()->exists()) {
            throw ValidationException::withMessages([
                'inputs' => __('This draft was entered under the old chipping method, which took raw husk at production. Cancel it and record the run again.'),
            ]);
        }
    }

    /**
     * The operator-entered output rows, checked against the recipe.
     *
     * Every row must be a positive measured quantity of a product the recipe allows, each
     * product once. A grade a batch did not produce is simply left out.
     *
     * @return Collection<int, ProductionOutput>
     */
    private function resolveOutputs(ProductionOrder $order, ProductionRecipe $recipe): Collection
    {
        $outputs = $order->outputs()->with('product')->orderBy('id')->get();

        if ($outputs->isEmpty()) {
            throw ValidationException::withMessages([
                'outputs' => __('Add at least one output with its measured quantity before posting.'),
            ]);
        }

        $allowed = $recipe->outputs->pluck('product_id')->map(fn ($id): int => (int) $id);
        $seen = [];

        foreach ($outputs->values() as $index => $output) {
            if (! $allowed->contains($output->product_id)) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.product_id" => __('Product :product is not an output of the husk chipping recipe.', [
                        'product' => $output->product?->code ?? __('unknown'),
                    ]),
                ]);
            }

            if (in_array($output->product_id, $seen, true)) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.product_id" => __('Each output product may appear only once.'),
                ]);
            }

            $seen[] = $output->product_id;

            if (! DecimalMath::isPositive(DecimalMath::of($output->quantity))) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.quantity" => __('Every output row needs a quantity greater than zero. Remove rows that produced nothing.'),
                ]);
            }
        }

        return $outputs;
    }
}
