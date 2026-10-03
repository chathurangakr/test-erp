<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Resources\V1\ApiResource;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use Illuminate\Http\Request;

/**
 * A delivery (dispatch note).
 *
 * Revenue follows the line's pricing basis and is the stored `sales_value` — raw shell priced
 * per KG is reported exactly as the delivery stored it, with no count-to-weight conversion.
 * Cost follows the stock movement: inventory cost, cost of goods sold and gross profit exist
 * only once the delivery has reached inventory, so for a DRAFT they are null. Gross profit and
 * margin come from the model's own methods; nothing is recalculated here.
 *
 * @mixin Delivery
 */
class DeliveryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $costed = $this->status->reachedInventory();

        return [
            'id' => $this->id,
            'delivery_number' => $this->delivery_number,
            'status' => $this->enum($this->status),
            'customer' => $this->ref($this->whenLoaded('customer', fn () => $this->customer, null)),
            'warehouse' => $this->ref($this->whenLoaded('warehouse', fn () => $this->warehouse, null)),
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder === null ? null : [
                'id' => $this->salesOrder->id,
                'so_number' => $this->salesOrder->so_number,
            ]),
            'delivery_date' => $this->date($this->delivery_date),
            'customer_reference' => $this->customer_reference,
            'vehicle_number' => $this->vehicle_number,
            'driver_name' => $this->driver_name,
            'notes' => $this->notes,
            'sales_total' => $this->whenLoaded('lines', fn () => $this->money($this->salesTotal())),
            'cost_of_goods_sold' => $this->whenLoaded('lines', fn () => $costed ? $this->money($this->costOfGoodsSold()) : null),
            'gross_profit' => $this->whenLoaded('lines', fn () => $costed ? $this->money($this->grossProfit()) : null),
            'gross_margin_percent' => $this->whenLoaded('lines', fn () => $costed ? $this->grossMarginPercent() : null),
            'actions' => [
                'can_edit' => $this->status->isEditable(),
                'can_post' => $this->status->canPost(),
                'can_reverse' => $this->status->canReverse() && ! $this->isReversed(),
                'can_cancel' => $this->status->canCancel(),
            ],
            'inventory_reference' => $this->whenLoaded('inventoryTransaction', fn () => $this->inventoryTransaction?->reference),
            'reversal_reference' => $this->whenLoaded('reversalInventoryTransaction', fn () => $this->reversalInventoryTransaction?->reference),
            'created_by' => $this->userRef($this->whenLoaded('createdBy', fn () => $this->createdBy, null)),
            'posted_by' => $this->userRef($this->whenLoaded('postedBy', fn () => $this->postedBy, null)),
            'posted_at' => $this->timestamp($this->posted_at),
            'reversed_by' => $this->userRef($this->whenLoaded('reversedBy', fn () => $this->reversedBy, null)),
            'reversed_at' => $this->timestamp($this->reversed_at),
            'created_at' => $this->timestamp($this->created_at),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (DeliveryLine $line): array => [
                'id' => $line->id,
                'product' => $this->ref($line->relationLoaded('product') ? $line->product : null),
                'sales_order_line_id' => $line->sales_order_line_id,
                'lot' => $line->relationLoaded('lot') && $line->lot !== null
                    ? ['id' => $line->lot->id, 'lot_number' => $line->lot->lot_number]
                    : null,
                'uom' => $line->product?->primaryUom?->code,
                'secondary_uom' => $line->product?->secondaryUom?->code,
                'quantity' => $this->quantity($line->quantity),
                'secondary_quantity' => $this->quantity($line->secondary_quantity),
                'pricing_basis' => $this->enum($line->pricing_basis),
                'unit_price' => $this->money($line->unit_price),
                'sales_value' => $this->money($line->sales_value),
                'inventory_unit_cost' => $costed ? $this->money($line->inventory_unit_cost) : null,
                // Includes backflush_cost: the full cost of what this line sold.
                'cost_of_goods_sold' => $costed ? $this->money($line->cost_of_goods_sold) : null,
                // Raw material used by the goods delivered (husk chipping outputs).
                'backflush' => $line->backflush_quantity === null ? null : [
                    'product' => $this->ref($line->relationLoaded('backflushProduct') ? $line->backflushProduct : null),
                    'quantity' => $this->quantity($line->backflush_quantity),
                    'cost' => $costed ? $this->money($line->backflush_cost) : null,
                ],
                'gross_profit' => $costed ? $line->grossProfit() : null,
                'gross_margin_percent' => $costed ? $line->grossMarginPercent() : null,
            ])->values()),
        ];
    }
}
