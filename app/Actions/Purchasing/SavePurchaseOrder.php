<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft purchase order, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. Input is expected to have
 * passed StorePurchaseOrderRequest / UpdatePurchaseOrderRequest. A purchase order creates no
 * inventory; only a DRAFT may be edited.
 */
class SavePurchaseOrder
{
    public function __construct(private readonly GeneratePurchasingReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?PurchaseOrder $order, array $data, ?int $userId = null): PurchaseOrder
    {
        if ($order !== null) {
            $this->assertEditable($order);
        }

        return DB::transaction(function () use ($order, $data, $userId): PurchaseOrder {
            $header = [
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($order === null) {
                $order = PurchaseOrder::create($header + [
                    'po_number' => $this->references->forPurchaseOrder(),
                    'status' => PurchaseOrderStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $order->update($header);

                // Safe only because the order is still a draft: nothing has been received, so
                // no received_quantity can be lost.
                $order->lines()->delete();
            }

            foreach ($data['lines'] as $line) {
                $quantity = DecimalMath::quantity(DecimalMath::of($line['quantity']));
                $price = DecimalMath::cost(DecimalMath::of($line['unit_price']));

                $order->lines()->create([
                    'product_id' => $line['product_id'],
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'line_total' => DecimalMath::cost(DecimalMath::mul($quantity, $price)),
                    'received_quantity' => '0.000',
                ]);
            }

            return $order->refresh();
        });
    }

    public function assertEditable(PurchaseOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft purchase order can be edited. :reference is :status.', [
                    'reference' => $order->po_number,
                    'status' => $order->status->label(),
                ]),
            ]);
        }
    }
}
