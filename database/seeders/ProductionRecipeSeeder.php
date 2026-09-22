<?php

namespace Database\Seeders;

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
     * Dehusking: one whole coconut becomes one dehusked coconut plus one raw husk.
     *
     * `ratio` of 1 for both outputs is what encodes the confirmed 1:1:1 rule
     * (docs/BUSINESS_RULES.md §2) — it is configuration, not a hard-coded constant.
     *
     * The 85 / 15 value split is a TEMPORARY placeholder agreed for Version 1. The factory
     * has not finalised its joint-product costing policy; when it does, only these numbers
     * change. The percentages must always total exactly 100.
     *
     * Husk chipping has NO fixed ratio: a `ratio` of null records that the operator
     * measures and enters the actual KG produced. Its 30 / 30 / 40 value split is likewise
     * provisional and configurable. See docs/BUSINESS_RULES.md §19.
     *
     * Copra mixes the two within one recipe, which is why the ratio is per OUTPUT rather
     * than per type:
     *
     *   COPRA      ratio null   — the operator weighs the copra actually dried
     *   RAW-SHELL  ratio 1.0    — one shell per coconut processed, a confirmed factory rule
     *              secondary    — the operator ALSO weighs the shell; no NOS-to-KG
     *                             conversion exists or may be invented
     *
     * Its 75 / 25 value split is provisional, pending the factory's joint-product costing
     * policy. See docs/BUSINESS_RULES.md §20.
     *
     * @var array<string, array{input: string, outputs: list<array{code: string, ratio: string|null, percent: string, secondary?: bool}>}>
     */
    private const RECIPES = [
        ProductionType::Dehusking->value => [
            'input' => 'COCONUT',
            'outputs' => [
                ['code' => 'DEHUSKED-COCONUT', 'ratio' => '1.000000', 'percent' => '85.000000'],
                ['code' => 'RAW-HUSK', 'ratio' => '1.000000', 'percent' => '15.000000'],
            ],
        ],
        ProductionType::HuskChipping->value => [
            'input' => 'RAW-HUSK',
            'outputs' => [
                ['code' => 'HUSK-7C', 'ratio' => null, 'percent' => '30.000000'],
                ['code' => 'HUSK-9C', 'ratio' => null, 'percent' => '30.000000'],
                ['code' => 'HUSK-10C', 'ratio' => null, 'percent' => '40.000000'],
            ],
        ],
        ProductionType::Copra->value => [
            'input' => 'DEHUSKED-COCONUT',
            'outputs' => [
                ['code' => 'COPRA', 'ratio' => null, 'percent' => '75.000000'],
                ['code' => 'RAW-SHELL', 'ratio' => '1.000000', 'percent' => '25.000000', 'secondary' => true],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::RECIPES as $type => $definition) {
            $input = $this->product($definition['input']);

            $recipe = ProductionRecipe::updateOrCreate(
                ['production_type' => $type],
                [
                    'input_product_id' => $input->id,
                    'notes' => __('Allocation percentages are provisional and configurable.'),
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
                        'sort_order' => $index,
                    ],
                );
            }

            // Refuse to leave a recipe that would create or destroy value on every run.
            $recipe->load('outputs')->assertAllocationIsComplete();
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
