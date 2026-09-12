<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
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
