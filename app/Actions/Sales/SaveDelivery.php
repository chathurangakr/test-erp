<?php

namespace App\Actions\Sales;

use App\Enums\DeliveryStatus;
use App\Enums\PricingBasis;
use App\Models\Delivery;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft delivery, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. A draft moves no stock: its
 * sales value is provisional (PostDelivery recalculates it authoritatively) and its inventory
 * cost and cost of goods sold stay null until stock actually moves. Only a DRAFT may be edited.
 */
class SaveDelivery
{
    public function __construct(private readonly GenerateSalesReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?Delivery $delivery, array $data, ?int $userId = null): Delivery
    {
        if ($delivery !== null) {
            $this->assertEditable($delivery);
        }

        return DB::transaction(function () use ($delivery, $data, $userId): Delivery {
            $header = [
                'customer_id' => $data['customer_id'],
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'delivery_date' => $data['delivery_date'],
                'customer_reference' => $data['customer_reference'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($delivery === null) {
                $delivery = Delivery::create($header + [
                    'delivery_number' => $this->references->forDelivery(),
                    'status' => DeliveryStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $delivery->update($header);

                // Safe only because the delivery is still a draft — no stock has moved.
                $delivery->lines()->delete();
            }

            foreach ($data['lines'] as $line) {
                $basis = PricingBasis::from($line['pricing_basis']);
                $quantity = DecimalMath::quantity(DecimalMath::of($line['quantity']));
                $secondary = ($line['secondary_quantity'] ?? null) === null
                    ? null
                    : DecimalMath::quantity(DecimalMath::of($line['secondary_quantity']));
                $price = DecimalMath::cost(DecimalMath::of($line['unit_price']));

                $sellingQuantity = $basis->isSecondary() ? ($secondary ?? '0') : $quantity;

                $delivery->lines()->create([
                    'product_id' => $line['product_id'],
                    'sales_order_line_id' => $line['sales_order_line_id'] ?? null,
                    'lot_id' => $line['lot_id'] ?? null,
                    'quantity' => $quantity,
                    'secondary_quantity' => $secondary,
                    'pricing_basis' => $basis,
                    'unit_price' => $price,
                    // Provisional until posting recalculates it authoritatively.
                    'sales_value' => DecimalMath::money(DecimalMath::mul($sellingQuantity, $price)),
                    // Cost is unknown until stock actually moves.
                    'inventory_unit_cost' => null,
                    'cost_of_goods_sold' => null,
                    // The raw material the goods used, for products that backflush one.
                    // Product and cost are settled by PostDelivery from the recipe and ledger.
                    'backflush_quantity' => ($line['backflush_quantity'] ?? null) === null || $line['backflush_quantity'] === ''
                        ? null
                        : DecimalMath::quantity(DecimalMath::of((string) $line['backflush_quantity'])),
                ]);
            }

            return $delivery->refresh();
        });
    }

    public function assertEditable(Delivery $delivery): void
    {
        if (! $delivery->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft delivery can be edited. :reference is :status.', [
                    'reference' => $delivery->delivery_number,
                    'status' => $delivery->status->label(),
                ]),
            ]);
        }
    }
}
