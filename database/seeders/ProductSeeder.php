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
     * @var list<array{code: string, name: string, primary: string, secondary: ?string, lots: bool, returnable?: bool}>
     */
    public const PRODUCTS = [
        ['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        // Coconuts bought and kept by size (2026-10-24). Each size is its own stock at its own
        // cost, whole and dehusked alike. The plain items above stay until their stock runs out.
        ['code' => 'COCONUT-LARGE', 'name' => 'Whole Coconut (Large)', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'COCONUT-SMALL', 'name' => 'Whole Coconut (Small)', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'COCONUT-IMMATURE', 'name' => 'Whole Coconut (Immature)', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'DEHUSKED-LARGE', 'name' => 'Dehusked Coconut (Large)', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'DEHUSKED-SMALL', 'name' => 'Dehusked Coconut (Small)', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
        ['code' => 'DEHUSKED-IMMATURE', 'name' => 'Dehusked Coconut (Immature)', 'primary' => 'NOS', 'secondary' => null, 'lots' => false],
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
        // Packing: bought, used, sometimes torn and written off. Counted like any stock item.
        ['code' => 'PP-BAG', 'name' => 'Woven Polypropylene Bag', 'primary' => 'NOS', 'secondary' => null, 'lots' => false, 'returnable' => true],
    ];

    /**
     * When true, only what does not exist yet is created; nothing existing is changed.
     */
    private bool $onlyMissing = false;

    /**
     * Add what a newer version introduced to an installed database, leaving every existing row
     * exactly as people left it. Run by `masters:add-new` on each update.
     */
    public function addMissing(): void
    {
        $this->onlyMissing = true;
        $this->run();
    }

    public function run(): void
    {
        $units = UnitOfMeasure::query()->pluck('id', 'code');

        foreach (self::PRODUCTS as $product) {
            if ($this->onlyMissing && Product::query()->where('code', $product['code'])->exists()) {
                continue;
            }

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
                    // Lent to customers with the goods and expected back (docs/INVENTORY_RULES.md §18).
                    'is_returnable' => $product['returnable'] ?? false,
                    'is_active' => true,
                ],
            );
        }
    }
}
