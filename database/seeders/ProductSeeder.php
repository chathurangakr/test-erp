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
     * `lots` false makes a product ONE pooled stock with no lot selection anywhere
     * (docs/INVENTORY_RULES.md §15). Copra and shell keep their lots.
     *
     * A husk product measured both by weight and by volume is TWO stock items — one per unit —
     * because the ledger holds each product's quantity in its own primary unit and no KG↔L
     * conversion exists. 50 KG of 7C and 100 L of 7C therefore never merge or net off
     * (docs/INVENTORY_RULES.md §16).
     *
     * @var list<array{code: string, name: string, primary: string, secondary: ?string, lots: bool}>
     */
    public const PRODUCTS = [
        ['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'COPRA', 'name' => 'Copra', 'primary' => 'KG', 'secondary' => null, 'lots' => true],
        ['code' => 'HUSK-7C', 'name' => '7C', 'primary' => 'KG', 'secondary' => null, 'lots' => false],
        ['code' => 'HUSK-9C', 'name' => '9C', 'primary' => 'KG', 'secondary' => null, 'lots' => false],
        ['code' => 'HUSK-10C', 'name' => '10C', 'primary' => 'KG', 'secondary' => null, 'lots' => false],
        ['code' => 'COCOPEAT', 'name' => 'Cocopeat', 'primary' => 'KG', 'secondary' => null, 'lots' => false],
        ['code' => 'HUSK-7C-L', 'name' => '7C (L)', 'primary' => 'L', 'secondary' => null, 'lots' => false],
        ['code' => 'HUSK-9C-L', 'name' => '9C (L)', 'primary' => 'L', 'secondary' => null, 'lots' => false],
        ['code' => 'HUSK-10C-L', 'name' => '10C (L)', 'primary' => 'L', 'secondary' => null, 'lots' => false],
        ['code' => 'COCOPEAT-L', 'name' => 'Cocopeat (L)', 'primary' => 'L', 'secondary' => null, 'lots' => false],
        // Raw Shell is sellable by count or by weight, so it carries both units. The
        // inventory unit for shell *quantity* is NOS; the UI may label it "Qty".
        ['code' => 'RAW-SHELL', 'name' => 'Raw Coconut Shell', 'primary' => 'NOS', 'secondary' => 'KG', 'lots' => true],
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
                    'tracks_lots' => $product['lots'],
                    'is_active' => true,
                ],
            );
        }
    }
}
