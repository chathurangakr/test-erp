<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Seeds the single Version 1 warehouse.
 *
 * Only MAIN is seeded. The example yards discussed for the future (Coconut Yard, Husk Yard,
 * Copra Store, Shell Yard, Finished Goods Store) are deliberately NOT created — they are
 * illustrations, not confirmed requirements.
 *
 * Idempotent: safe to run repeatedly.
 */
class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        Warehouse::updateOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Warehouse',
                'is_default' => true,
                'is_active' => true,
            ],
        );
    }
}
