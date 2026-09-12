<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => mb_strtoupper($this->faker->unique()->bothify('WH-####')),
            'name' => $this->faker->words(2, true).' Store',
            'description' => null,
            'address' => null,
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    /**
     * Marks this warehouse as the default. Callers are responsible for ensuring no other
     * default exists — production code must use SetDefaultWarehouse instead.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
