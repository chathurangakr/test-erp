<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the initial coconut product master.
 *
 * Products are seeded rather than hard-coded in application logic, so the factory can add
 * further products without a code change. Idempotent — safe to re-run.
 */
class ProductSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, primary: string, secondary: ?string}>
     */
    private const PRODUCTS = [
        ['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary' => 'NOS', 'secondary' => null],
        ['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary' => 'NOS', 'secondary' => null],
        ['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary' => 'NOS', 'secondary' => null],
        ['code' => 'COPRA', 'name' => 'Copra', 'primary' => 'KG', 'secondary' => null],
        ['code' => 'HUSK-7C', 'name' => '7C', 'primary' => 'KG', 'secondary' => null],
        ['code' => 'HUSK-9C', 'name' => '9C', 'primary' => 'KG', 'secondary' => null],
        ['code' => 'HUSK-10C', 'name' => '10C', 'primary' => 'KG', 'secondary' => null],
        // Raw Shell is sellable by count or by weight, so it carries both units. The
        // inventory unit for shell *quantity* is NOS; the UI may label it "Qty".
        ['code' => 'RAW-SHELL', 'name' => 'Raw Coconut Shell', 'primary' => 'NOS', 'secondary' => 'KG'],
    ];

    public function run(): void
    {
        $units = UnitOfMeasure::query()->pluck('id', 'code');

        foreach (self::PRODUCTS as $product) {
            $primaryId = $units[$product['primary']] ?? null;

            if ($primaryId === null) {
                throw new RuntimeException(
                    "Unit of measure [{$product['primary']}] is missing. Run UnitOfMeasureSeeder first."
                );
            }

            $secondaryId = $product['secondary'] === null
                ? null
                : ($units[$product['secondary']] ?? throw new RuntimeException(
                    "Unit of measure [{$product['secondary']}] is missing. Run UnitOfMeasureSeeder first."
                ));

            Product::updateOrCreate(
                ['code' => $product['code']],
                [
                    'name' => $product['name'],
                    'primary_uom_id' => $primaryId,
                    'secondary_uom_id' => $secondaryId,
                    'is_active' => true,
                ],
            );
        }
    }
}
