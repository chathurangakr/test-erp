<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => mb_strtoupper($this->faker->unique()->bothify('PROD-####')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'primary_uom_id' => UnitOfMeasure::factory(),
            'secondary_uom_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    /**
     * Give the product a distinct secondary unit of measure, as Raw Coconut Shell has.
     */
    public function withSecondaryUom(): static
    {
        return $this->state(fn (array $attributes): array => [
            'secondary_uom_id' => UnitOfMeasure::factory(),
        ]);
    }
}
