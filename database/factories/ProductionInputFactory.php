<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionInput>
 */
class ProductionInputFactory extends Factory
{
    protected $model = ProductionInput::class;

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
            // Cost is unknown until the ledger values the consumption.
            'unit_cost' => null,
            'total_cost' => null,
        ];
    }
}
