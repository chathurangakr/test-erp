<?php

namespace App\Models;

use App\Enums\InputConsumption;
use App\Enums\ProductionType;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * How a production type turns one input into its outputs, and how it shares value.
 *
 * This is the ONLY place that knows which products a production type involves. The posting
 * action resolves everything through the recipe, so product codes never appear in
 * controllers or services — only in the seeder.
 *
 * A type may hold several VERSIONS; exactly one is active. A superseded version is
 * deactivated, never deleted, and posted runs keep the figures they stored.
 *
 * See docs/BUSINESS_RULES.md §18-§19.
 */
class ProductionRecipe extends Model
{
    protected $fillable = [
        'production_type',
        'version',
        'input_product_id',
        'input_consumption',
        'default_labour_rate',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'production_type' => ProductionType::class,
            'version' => 'integer',
            'input_consumption' => InputConsumption::class,
            'default_labour_rate' => 'decimal:6',
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
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Whether this recipe leaves its input in stock until its outputs are delivered.
     *
     * Such a run posts its outputs with the raw material cost PENDING; the input is
     * backflushed — issued at its weighted average and added to cost of goods sold — by the
     * delivery. Its outputs carry no allocation share, because there is nothing to share.
     */
    public function consumesInputAtDelivery(): bool
    {
        return $this->input_consumption === InputConsumption::AtDelivery;
    }

    /**
     * The output that carries the run's labour as cost, if this recipe has one.
     */
    public function labourOutput(): ?ProductionRecipeOutput
    {
        return $this->outputs->first(fn (ProductionRecipeOutput $output): bool => $output->absorbs_labour);
    }

    /**
     * The active recipe that defers its input to the delivery of the given product.
     *
     * This is how a delivery knows that a line needs a raw-material quantity: the product is
     * an output of a backflushing recipe. No product code is consulted anywhere.
     */
    public static function backflushingFor(int $productId): ?self
    {
        return static::query()
            ->with(['inputProduct.primaryUom', 'outputs'])
            ->where('is_active', true)
            ->where('input_consumption', InputConsumption::AtDelivery)
            ->whereHas('outputs', fn ($query) => $query->where('product_id', $productId))
            ->first();
    }

    /**
     * Every product whose delivery backflushes a raw material, keyed by product id, with the
     * raw material it backflushes.
     *
     * @return Collection<int, Product>
     */
    public static function backflushInputsByOutput(): Collection
    {
        $map = collect();

        static::query()
            ->with(['inputProduct.primaryUom', 'outputs'])
            ->where('is_active', true)
            ->where('input_consumption', InputConsumption::AtDelivery)
            ->get()
            ->each(function (self $recipe) use ($map): void {
                foreach ($recipe->outputs as $output) {
                    $map->put($output->product_id, $recipe->inputProduct);
                }
            });

        return $map;
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

        if ($this->outputs->filter(fn (ProductionRecipeOutput $output): bool => $output->absorbs_labour)->count() > 1) {
            throw ValidationException::withMessages([
                'recipe' => __('Production recipe :type names more than one output to carry labour cost.', [
                    'type' => $this->production_type->label(),
                ]),
            ]);
        }

        // Nothing is consumed when such a run is posted, so there is no value to share and
        // no percentage to check. The input's cost reaches the outputs at delivery instead.
        if ($this->consumesInputAtDelivery()) {
            return;
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
