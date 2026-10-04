<?php

namespace App\Actions\Sales;

use App\Enums\PricingBasis;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft sales order, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. Input is expected to have
 * passed StoreSalesOrderRequest / UpdateSalesOrderRequest. A sales order creates and reserves
 * no inventory; only a DRAFT may be edited. The priced quantity is derived from the line's
 * pricing basis — never typed separately.
 */
class SaveSalesOrder
{
    public function __construct(private readonly GenerateSalesReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?SalesOrder $order, array $data, ?int $userId = null): SalesOrder
    {
        if ($order !== null) {
            $this->assertEditable($order);
        }

        return DB::transaction(function () use ($order, $data, $userId): SalesOrder {
            $header = [
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'customer_reference' => $data['customer_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($order === null) {
                $order = SalesOrder::create($header + [
                    'so_number' => $this->references->forSalesOrder(),
                    'status' => SalesOrderStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $order->update($header);

                // Safe only because the order is still a draft: nothing has been delivered, so
                // no delivered_quantity can be lost.
                $order->lines()->delete();
            }

            foreach ($data['lines'] as $line) {
                $basis = PricingBasis::from($line['pricing_basis']);
                $quantity = DecimalMath::quantity(DecimalMath::of($line['quantity']));
                $secondary = ($line['secondary_quantity'] ?? null) === null
                    ? null
                    : DecimalMath::quantity(DecimalMath::of($line['secondary_quantity']));
                $price = DecimalMath::cost(DecimalMath::of($line['unit_price']));

                // The priced quantity is derived from the basis, never typed separately.
                $sellingQuantity = $basis->isSecondary() ? ($secondary ?? '0') : $quantity;

                $order->lines()->create([
                    'product_id' => $line['product_id'],
                    'quantity' => $quantity,
                    'secondary_quantity' => $secondary,
                    'pricing_basis' => $basis,
                    'unit_price' => $price,
                    'line_total' => DecimalMath::money(DecimalMath::mul($sellingQuantity, $price)),
                    'delivered_quantity' => '0.000',
                ]);
            }

            return $order->refresh();
        });
    }

    public function assertEditable(SalesOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft sales order can be edited. :reference is :status.', [
                    'reference' => $order->so_number,
                    'status' => $order->status->label(),
                ]),
            ]);
        }
    }
}
