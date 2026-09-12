<?php

namespace Database\Seeders;

use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

/**
 * Seeds the units of measure used by the initial product master.
 *
 * Idempotent: safe to run repeatedly without creating duplicates.
 */
class UnitOfMeasureSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, decimal_places: int}>
     */
    private const UNITS = [
        // NOS = countable physical items. KG = weight. These are the only two units the
        // business needs; "Qty" is UI wording for a NOS count, not a separate unit.
        // See docs/DATA_MODEL.md decision D-1.
        ['code' => 'NOS', 'name' => 'Numbers', 'decimal_places' => 0],
        ['code' => 'KG', 'name' => 'Kilogram', 'decimal_places' => 3],
    ];

    public function run(): void
    {
        foreach (self::UNITS as $unit) {
            UnitOfMeasure::updateOrCreate(
                ['code' => $unit['code']],
                [
                    'name' => $unit['name'],
                    'decimal_places' => $unit['decimal_places'],
                    'is_active' => true,
                ],
            );
        }
    }
}
