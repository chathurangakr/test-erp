<?php

namespace App\Http\Resources\V1\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Http\Resources\V1\ApiResource;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use Illuminate\Http\Request;

/**
 * A goods receipt.
 *
 * Cost figures are the ones PostGoodsReceipt stored — the landed unit cost that reached
 * inventory. A DRAFT has calculated nothing yet, so its cost fields are null rather than a
 * preview: the API only ever reports authoritative figures.
 *
 * @mixin GoodsReceipt
 */
class GoodsReceiptResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isDraft = $this->status === GoodsReceiptStatus::Draft;

        return [
            'id' => $this->id,
            'grn_number' => $this->grn_number,
            'status' => $this->enum($this->status),
            'supplier' => $this->ref($this->whenLoaded('supplier', fn () => $this->supplier, null)),
            'warehouse' => $this->ref($this->whenLoaded('warehouse', fn () => $this->warehouse, null)),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder === null ? null : [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
            ]),
            'receipt_date' => $this->date($this->receipt_date),
            'supplier_delivery_reference' => $this->supplier_delivery_reference,
            'transport_cost' => $this->money($this->transport_cost),
            'loading_cost' => $this->money($this->loading_cost),
            'other_cost' => $this->money($this->other_cost),
            'landed_cost_total' => $this->money($this->landedCostTotal()),
            'notes' => $this->notes,
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
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (GoodsReceiptLine $line): array => [
                'id' => $line->id,
                'product' => $this->ref($line->relationLoaded('product') ? $line->product : null),
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'uom' => $line->product?->primaryUom?->code,
                'secondary_uom' => $line->product?->secondaryUom?->code,
                'quantity' => $this->quantity($line->quantity),
                'secondary_quantity' => $this->quantity($line->secondary_quantity),
                'unit_price' => $this->money($line->unit_price),
                'material_cost' => $isDraft ? null : $this->money($line->material_cost),
                'allocated_landed_cost' => $isDraft ? null : $this->money($line->allocated_landed_cost),
                'final_unit_cost' => $isDraft ? null : $this->money($line->final_unit_cost),
                'supplier_lot_number' => $line->supplier_lot_number,
                'lot_number' => $line->relationLoaded('lot') ? $line->lot?->lot_number : null,
            ])->values()),
        ];
    }
}
