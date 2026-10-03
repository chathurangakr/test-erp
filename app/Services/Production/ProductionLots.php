<?php

namespace App\Services\Production;

use App\Actions\Inventory\CreateInventoryLot;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Support\RecordText;
use Illuminate\Validation\ValidationException;

/**
 * The lot rules every production type shares, decided by each product's `tracks_lots` flag.
 *
 *  - A product that tracks lots keeps full traceability: its input lot must be chosen, and
 *    each output gets a new lot recording the run as its source.
 *  - A POOLED product (tracks_lots = false) is one stock with no lots: no input lot may be
 *    chosen and no output lot is created. Traceability is to the production order itself.
 *
 * Copra, for example, consumes pooled dehusked coconut but still gives its copra and shell
 * outputs lots. See docs/INVENTORY_RULES.md §15.
 */
class ProductionLots
{
    public function __construct(private readonly CreateInventoryLot $createLot) {}

    /**
     * @param  string  $missingMessage  shown when a lot-tracked input has no lot chosen
     */
    public function assertInputLot(ProductionInput $input, string $missingMessage): void
    {
        $product = $input->product;

        if ($product !== null && ! $product->tracksLots()) {
            if ($input->lot_id !== null) {
                throw ValidationException::withMessages([
                    'inputs.0.lot_id' => __(':product is kept as one pooled stock without lots, so no lot can be chosen.', [
                        'product' => $product->code,
                    ]),
                ]);
            }

            return;
        }

        if ($input->lot_id === null) {
            throw ValidationException::withMessages([
                'inputs.0.lot_id' => $missingMessage,
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
    }

    /**
     * A new lot for an output of this run, or null when the output product is pooled.
     */
    public function outputLot(Product $product, ProductionOrder $order, ?ProductionInput $input): ?InventoryLot
    {
        if (! $product->tracksLots()) {
            return null;
        }

        $notes = $input === null
            ? RecordText::of('Produced by :reference', ['reference' => $order->production_number])
            : RecordText::of('Produced by :reference from lot :lot', [
                'reference' => $order->production_number,
                'lot' => $input->lot?->lot_number ?? RecordText::of('unlotted stock'),
            ]);

        return $this->createLot->handle($product, [
            'source_type' => ProductionOrder::class,
            'source_id' => $order->getKey(),
            'manufactured_at' => $order->production_date,
            'received_at' => $order->production_date,
            'notes' => $notes,
        ]);
    }
}
