<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionRecipeOutput extends Model
{
    protected $fillable = [
        'production_recipe_id',
        'product_id',
        'output_ratio',
        'allocation_percent',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'output_ratio' => 'decimal:6',
            'allocation_percent' => 'decimal:6',
            'sort_order' => 'integer',
        ];
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
