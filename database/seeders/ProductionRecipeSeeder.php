<?php

namespace Database\Seeders;

use App\Enums\InputConsumption;
use App\Enums\ProductionType;
use App\Models\Product;
use App\Models\ProductionRecipe;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the production recipes.
 *
 * This seeder is the ONLY place in the application where production product codes appear.
 * Everything downstream resolves products through the recipe, so changing what dehusking
 * consumes or produces is a data change rather than a code change.
 *
 * Idempotent: safe to run repeatedly.
 */
class ProductionRecipeSeeder extends Seeder
{
    /**
     * The CURRENT version of each recipe. Running the seeder makes that version the only
     * active one for its type; earlier versions are deactivated, never deleted, and every
     * posted run keeps the figures it stored.
     *
     * DEHUSKING (version 2): one whole coconut becomes one dehusked coconut plus one raw husk.
     * `ratio` 1 for both outputs encodes the confirmed 1:1:1 rule. The dehusked coconut
     * carries 100% of the coconut's value; the raw husk carries NONE of it. Instead the husk's
     * cost is the dehusking LABOUR — quantity x the per-coconut rate the operator confirms on
     * each run, pre-filled from `labour_rate` — Rs. 4.00, the owner's current rate. It changes
     * from time to time: the operator can override it on any run, and each run stores the rate
     * it used, so changing this default never alters a posted run. So input value + labour =
     * outputs, exactly. (Version 1 split 85 / 15 and had no labour.)
     *
     * HUSK CHIPPING (version 2): consumes NOTHING when posted. The operator records the
     * measured outputs — any of these grades, by weight or by volume — and they enter stock
     * with their raw-husk cost PENDING. The husks are backflushed when the outputs are
     * delivered. There is no allocation share because there is no value to share, and NO
     * ratio, because there is no fixed conversion from husks to KG or litres. (Version 1
     * consumed husk at production and split 30 / 30 / 40.)
     *
     * COPRA (version 2) mixes measured and derived outputs within one recipe:
     *
     *   COPRA      ratio null   — the operator weighs the copra actually dried
     *   RAW-SHELL  ratio 1.0    — one shell per coconut processed, a confirmed factory rule
     *              weight       — OPTIONAL since version 2 (the factory has no scale for
     *                             shell); recorded when measured, never converted from the
     *                             count. (Version 1 required the shell weight.)
     *
     * Its 75 / 25 value split is provisional, pending the factory's joint-product costing
     * policy. See docs/BUSINESS_RULES.md §18-§20.
     *
     * @var array<string, array{
     *     version: int,
     *     input: string,
     *     consumption: InputConsumption,
     *     labour_rate: string|null,
     *     notes: string,
     *     outputs: list<array{code: string, ratio: string|null, percent: string, secondary?: bool, labour?: bool}>
     * }>
     */
    private const RECIPES = [
        ProductionType::Dehusking->value => [
            'version' => 2,
            'input' => 'COCONUT',
            'consumption' => InputConsumption::AtProduction,
            'labour_rate' => '4.000000',
            'notes' => 'Dehusked coconut carries the coconut value; raw husk carries the dehusking labour.',
            'outputs' => [
                ['code' => 'DEHUSKED-COCONUT', 'ratio' => '1.000000', 'percent' => '100.000000'],
                ['code' => 'RAW-HUSK', 'ratio' => '1.000000', 'percent' => '0.000000', 'labour' => true],
            ],
        ],
        ProductionType::HuskChipping->value => [
            'version' => 2,
            'input' => 'RAW-HUSK',
            'consumption' => InputConsumption::AtDelivery,
            'labour_rate' => null,
            'notes' => 'Raw husk is backflushed when the outputs are delivered.',
            'outputs' => [
                ['code' => 'HUSK-7C', 'ratio' => null, 'percent' => '0.000000'],
                ['code' => 'HUSK-9C', 'ratio' => null, 'percent' => '0.000000'],
                ['code' => 'HUSK-10C', 'ratio' => null, 'percent' => '0.000000'],
                ['code' => 'COCOPEAT', 'ratio' => null, 'percent' => '0.000000'],
                ['code' => 'HUSK-7C-L', 'ratio' => null, 'percent' => '0.000000'],
                ['code' => 'HUSK-9C-L', 'ratio' => null, 'percent' => '0.000000'],
                ['code' => 'HUSK-10C-L', 'ratio' => null, 'percent' => '0.000000'],
                ['code' => 'COCOPEAT-L', 'ratio' => null, 'percent' => '0.000000'],
            ],
        ],
        ProductionType::Copra->value => [
            'version' => 2,
            'input' => 'DEHUSKED-COCONUT',
            'consumption' => InputConsumption::AtProduction,
            'labour_rate' => null,
            'notes' => 'Allocation percentages are provisional and configurable.',
            'outputs' => [
                ['code' => 'COPRA', 'ratio' => null, 'percent' => '75.000000'],
                ['code' => 'RAW-SHELL', 'ratio' => '1.000000', 'percent' => '25.000000'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::RECIPES as $type => $definition) {
            $input = $this->product($definition['input']);

            $recipe = ProductionRecipe::updateOrCreate(
                ['production_type' => $type, 'version' => $definition['version']],
                [
                    'input_product_id' => $input->id,
                    'input_consumption' => $definition['consumption'],
                    'default_labour_rate' => $definition['labour_rate'],
                    'notes' => $definition['notes'],
                    'is_active' => true,
                ],
            );

            foreach ($definition['outputs'] as $index => $output) {
                $recipe->outputs()->updateOrCreate(
                    ['product_id' => $this->product($output['code'])->id],
                    [
                        'output_ratio' => $output['ratio'],
                        'requires_secondary_quantity' => $output['secondary'] ?? false,
                        'allocation_percent' => $output['percent'],
                        'absorbs_labour' => $output['labour'] ?? false,
                        'sort_order' => $index,
                    ],
                );
            }

            // Refuse to leave a recipe that would create or destroy value on every run.
            $recipe->load('outputs')->assertAllocationIsComplete();

            // Exactly one active version per type. Superseded versions stay for the record.
            ProductionRecipe::query()
                ->where('production_type', $type)
                ->whereKeyNot($recipe->getKey())
                ->update(['is_active' => false]);
        }
    }

    private function product(string $code): Product
    {
        $product = Product::query()->where('code', $code)->first();

        if ($product === null) {
            throw new RuntimeException(
                "Product [{$code}] is missing. Run ProductSeeder before ProductionRecipeSeeder."
            );
        }

        return $product;
    }
}
