<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionOutput>
 */
class ProductionOutputFactory extends Factory
{
    protected $model = ProductionOutput::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_order_id' => ProductionOrder::factory(),
            'product_id' => Product::factory(),
            'lot_id' => null,
            'quantity' => '1000.000',
            'allocation_percent' => '0.000000',
            'allocated_cost' => '0.000000',
            'unit_cost' => '0.000000',
        ];
    }
}
