<?php

namespace App\Models;

use App\Enums\ProductionType;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * How a production type turns one input into its outputs, and how it shares value.
 *
 * This is the ONLY place that knows which products a production type involves. The posting
 * action resolves everything through the recipe, so product codes never appear in
 * controllers or services — only in the seeder.
 *
 * See docs/BUSINESS_RULES.md §18.
 */
class ProductionRecipe extends Model
{
    protected $fillable = [
        'production_type',
        'input_product_id',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'production_type' => ProductionType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function inputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'input_product_id');
    }

    /**
     * @return HasMany<ProductionRecipeOutput, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(ProductionRecipeOutput::class)->orderBy('sort_order')->orderBy('id');
    }

    public static function forType(ProductionType $type): ?self
    {
        return static::query()
            ->with(['inputProduct', 'outputs.product'])
            ->where('production_type', $type)
            ->where('is_active', true)
            ->first();
    }

    /** Sum of the output allocation percentages, decimal-safely. */
    public function allocationTotal(): string
    {
        return DecimalMath::round($this->outputs->reduce(
            fn (string $carry, ProductionRecipeOutput $output): string => DecimalMath::add(
                $carry,
                DecimalMath::of($output->allocation_percent),
            ),
            DecimalMath::zero(),
        ), 6);
    }

    /**
     * A recipe whose shares do not total 100% would create or destroy inventory value on
     * every run, so posting must refuse to use it.
     */
    public function assertAllocationIsComplete(): void
    {
        if ($this->outputs->isEmpty()) {
            throw ValidationException::withMessages([
                'recipe' => __('Production recipe :type has no outputs configured.', [
                    'type' => $this->production_type->label(),
                ]),
            ]);
        }

        $total = $this->allocationTotal();

        if (DecimalMath::compare($total, '100', 6) !== 0) {
            throw ValidationException::withMessages([
                'recipe' => __('Production recipe :type allocates :total% of input value; it must allocate exactly 100%.', [
                    'type' => $this->production_type->label(),
                    'total' => DecimalMath::round($total, 4),
                ]),
            ]);
        }
    }
}
