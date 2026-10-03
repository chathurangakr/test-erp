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
        // NOS = countable physical items. KG = weight. L = volume, for the husk products
        // the factory also measures by the litre. "Qty" is UI wording for a NOS count, not a
        // separate unit. NO conversion exists between any two of them — a KG balance and an
        // L balance of the same grade are separate stock items (docs/INVENTORY_RULES.md §16).
        // See docs/DATA_MODEL.md decision D-1.
        ['code' => 'NOS', 'name' => 'Numbers', 'decimal_places' => 0],
        ['code' => 'KG', 'name' => 'Kilogram', 'decimal_places' => 3],
        ['code' => 'L', 'name' => 'Litre', 'decimal_places' => 3],
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
