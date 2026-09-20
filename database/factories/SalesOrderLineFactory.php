<?php

namespace Database\Factories;

use App\Enums\PricingBasis;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderLine>
 */
class SalesOrderLineFactory extends Factory
{
    protected $model = SalesOrderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'product_id' => Product::factory(),
            'quantity' => '100.000',
            'secondary_quantity' => null,
            'pricing_basis' => PricingBasis::Primary,
            'unit_price' => '130.000000',
            'line_total' => '13000.000000',
            'delivered_quantity' => '0.000',
            'delivered_secondary_quantity' => null,
        ];
    }
}
