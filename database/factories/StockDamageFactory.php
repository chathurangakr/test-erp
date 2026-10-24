<?php

namespace Database\Factories;

use App\Enums\StockDamageStatus;
use App\Models\StockDamage;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare write-off header, for screens and access tests. Real write-offs — with ledger lines
 * and a journal — are made through App\Actions\Inventory\RecordStockDamage.
 *
 * @extends Factory<StockDamage>
 */
class StockDamageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'damage_number' => 'DMG-'.fake()->unique()->numerify('20260915-######'),
            'damage_date' => '2026-09-15',
            'warehouse_id' => Warehouse::factory(),
            'reason' => fake()->sentence(3),
            'status' => StockDamageStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
