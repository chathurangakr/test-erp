<?php

namespace App\Http\Resources\V1\Purchasing;

use App\Http\Resources\V1\ApiResource;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;

/**
 * A purchase order. Lists carry the header; the detail endpoint also loads its lines and the
 * goods receipts raised against it. A purchase order never represents stock.
 *
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'status' => $this->enum($this->status),
            'supplier' => $this->ref($this->whenLoaded('supplier', fn () => $this->supplier, null)),
            'warehouse' => $this->ref($this->whenLoaded('warehouse', fn () => $this->warehouse, null)),
            'order_date' => $this->date($this->order_date),
            'expected_date' => $this->date($this->expected_date),
            'supplier_reference' => $this->supplier_reference,
            'notes' => $this->notes,
            'material_total' => $this->whenLoaded('lines', fn () => $this->money($this->materialTotal())),
            'actions' => [
                'can_edit' => $this->status->isEditable(),
                'can_approve' => $this->status->canApprove(),
                'can_cancel' => $this->status->canCancel(),
            ],
            'created_by' => $this->userRef($this->whenLoaded('createdBy', fn () => $this->createdBy, null)),
            'approved_by' => $this->userRef($this->whenLoaded('approvedBy', fn () => $this->approvedBy, null)),
            'approved_at' => $this->timestamp($this->approved_at),
            'cancelled_by' => $this->userRef($this->whenLoaded('cancelledBy', fn () => $this->cancelledBy, null)),
            'cancelled_at' => $this->timestamp($this->cancelled_at),
            'created_at' => $this->timestamp($this->created_at),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (PurchaseOrderLine $line): array => [
                'id' => $line->id,
                'product' => $this->ref($line->relationLoaded('product') ? $line->product : null),
                'uom' => $line->product?->primaryUom?->code,
                'quantity' => $this->quantity($line->quantity),
                'unit_price' => $this->money($line->unit_price),
                'line_total' => $this->money($line->line_total),
                'received_quantity' => $this->quantity($line->received_quantity),
                'outstanding_quantity' => $this->quantity($line->outstandingQuantity()),
            ])->values()),
            'goods_receipts' => $this->whenLoaded('goodsReceipts', fn () => $this->goodsReceipts->map(fn (GoodsReceipt $grn): array => [
                'id' => $grn->id,
                'grn_number' => $grn->grn_number,
                'receipt_date' => $this->date($grn->receipt_date),
                'status' => $this->enum($grn->status),
            ])->values()),
        ];
    }
}
