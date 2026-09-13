<?php

namespace Database\Factories;

use App\Models\InventoryLot;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLot>
 */
class InventoryLotFactory extends Factory
{
    protected $model = InventoryLot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lot_number' => 'LOT-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'product_id' => Product::factory(),
            'supplier_lot_number' => null,
            'source_type' => null,
            'source_id' => null,
            'manufactured_at' => null,
            'received_at' => now()->toDateString(),
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
