<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Resources\V1\ApiResource;
use App\Models\Delivery;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Http\Request;

/**
 * A sales order. Lists carry the header; the detail endpoint also loads its lines and the
 * deliveries raised against it. A sales order never creates or reserves stock.
 *
 * @mixin SalesOrder
 */
class SalesOrderResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'so_number' => $this->so_number,
            'status' => $this->enum($this->status),
            'customer' => $this->ref($this->whenLoaded('customer', fn () => $this->customer, null)),
            'warehouse' => $this->ref($this->whenLoaded('warehouse', fn () => $this->warehouse, null)),
            'order_date' => $this->date($this->order_date),
            'expected_delivery_date' => $this->date($this->expected_delivery_date),
            'customer_reference' => $this->customer_reference,
            'notes' => $this->notes,
            'sales_total' => $this->whenLoaded('lines', fn () => $this->money($this->salesTotal())),
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
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (SalesOrderLine $line): array => [
                'id' => $line->id,
                'product' => $this->ref($line->relationLoaded('product') ? $line->product : null),
                'uom' => $line->product?->primaryUom?->code,
                'secondary_uom' => $line->product?->secondaryUom?->code,
                'quantity' => $this->quantity($line->quantity),
                'secondary_quantity' => $this->quantity($line->secondary_quantity),
                'pricing_basis' => $this->enum($line->pricing_basis),
                'unit_price' => $this->money($line->unit_price),
                'line_total' => $this->money($line->line_total),
                'delivered_quantity' => $this->quantity($line->delivered_quantity),
                'outstanding_quantity' => $this->quantity($line->outstandingQuantity()),
            ])->values()),
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn (Delivery $delivery): array => [
                'id' => $delivery->id,
                'delivery_number' => $delivery->delivery_number,
                'delivery_date' => $this->date($delivery->delivery_date),
                'status' => $this->enum($delivery->status),
            ])->values()),
        ];
    }
}
