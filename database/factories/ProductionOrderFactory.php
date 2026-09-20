<?php

namespace Database\Factories;

use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionOrder>
 */
class ProductionOrderFactory extends Factory
{
    protected $model = ProductionOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_number' => 'PRD-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'production_type' => ProductionType::Dehusking,
            'production_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::factory(),
            'status' => ProductionStatus::Draft,
            'notes' => null,
            'created_by' => null,
        ];
    }
}
