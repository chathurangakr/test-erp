<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Product master record.
 *
 * This model intentionally exposes no stock quantity. On-hand stock is derived from the
 * inventory ledger — see docs/INVENTORY_RULES.md.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'primary_uom_id',
        'secondary_uom_id',
        'tracks_lots',
        'is_active',
    ];

    /**
     * New products keep lots unless master data says otherwise.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tracks_lots' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tracks_lots' => 'boolean',
        ];
    }

    /**
     * Whether this product's stock is kept in lots.
     *
     * A product that does not track lots is ONE pooled stock: its new movements carry no lot,
     * and no screen asks for one. Old lots it held before it was pooled keep their history.
     * See docs/INVENTORY_RULES.md §15.
     */
    public function tracksLots(): bool
    {
        return (bool) $this->tracks_lots;
    }

    /**
     * How this product's stock movements reach the general ledger.
     *
     * Null means the product has no accounting meaning yet. Once the cutover date is set, a
     * movement of an unmapped product is refused rather than guessed at.
     *
     * @return HasOne<ProductAccountMapping, $this>
     */
    public function accountMapping(): HasOne
    {
        return $this->hasOne(ProductAccountMapping::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function primaryUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'primary_uom_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function secondaryUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'secondary_uom_id');
    }

    /**
     * Free-text search across product code and name.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%");
        });
    }
}
