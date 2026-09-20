<?php

namespace Database\Factories;

use App\Enums\PricingBasis;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryLine>
 */
class DeliveryLineFactory extends Factory
{
    protected $model = DeliveryLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'sales_order_line_id' => null,
            'product_id' => Product::factory(),
            'lot_id' => null,
            'quantity' => '100.000',
            'secondary_quantity' => null,
            'pricing_basis' => PricingBasis::Primary,
            'unit_price' => '130.000000',
            'sales_value' => '0.000000',
            // Cost columns stay null until posting fills them from the ledger.
            'inventory_unit_cost' => null,
            'cost_of_goods_sold' => null,
        ];
    }
}
