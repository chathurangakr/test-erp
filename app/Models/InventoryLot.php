<?php

namespace App\Models;

use Database\Factories\InventoryLotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lot / batch of a single product.
 *
 * Lot numbers follow LOT-YYYYMMDD-000001 and deliberately encode no business meaning —
 * see docs/INVENTORY_RULES.md §6. The originating document is recorded separately in
 * source_type / source_id so the permanent identifier never needs to change.
 */
class InventoryLot extends Model
{
    /** @use HasFactory<InventoryLotFactory> */
    use HasFactory;

    protected $fillable = [
        'lot_number',
        'product_id',
        'supplier_lot_number',
        'source_type',
        'source_id',
        'manufactured_at',
        'received_at',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'date',
            'received_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<InventoryTransactionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryTransactionLine::class, 'lot_id');
    }

    /**
     * @param  Builder<InventoryLot>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('lot_number', 'like', "%{$term}%")
                ->orWhere('supplier_lot_number', 'like', "%{$term}%");
        });
    }
}
