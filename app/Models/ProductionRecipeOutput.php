<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product a recipe produces, and how it is quantified and costed.
 *
 *   output_ratio                — units produced per unit of input, or NULL when no such
 *                                 relationship exists and the operator measures the output
 *   requires_secondary_quantity — the output must ALSO be measured in its secondary unit
 *   allocation_percent          — share of the consumed inventory value it carries
 *
 * See docs/BUSINESS_RULES.md §20.
 */
class ProductionRecipeOutput extends Model
{
    protected $fillable = [
        'production_recipe_id',
        'product_id',
        'output_ratio',
        'requires_secondary_quantity',
        'allocation_percent',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'output_ratio' => 'decimal:6',
            'requires_secondary_quantity' => 'boolean',
            'allocation_percent' => 'decimal:6',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Whether this output's quantity is calculated from the input.
     *
     * The presence of a ratio is what decides it — NULL means no fixed relationship exists,
     * so the operator must measure the output. This is the per-output authority; the
     * type-level `ProductionType::hasFixedOutputRatio()` is only a summary, and copra mixes
     * both kinds within a single recipe.
     */
    public function derivesQuantity(): bool
    {
        return $this->output_ratio !== null;
    }

    /**
     * @return BelongsTo<ProductionRecipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
