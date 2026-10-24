<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One product written off. Its cost is what the ledger charged, written once when recorded.
 */
class StockDamageLine extends Model
{
    protected $fillable = [
        'stock_damage_id',
        'product_id',
        'lot_id',
        'quantity',
        'uom_id',
        'unit_cost',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            // Only the cost read back from the ledger, once.
            $allowed = $line->getOriginal('total_cost') === null ? ['unit_cost', 'total_cost', 'updated_at'] : [];
            $changed = array_diff(array_keys($line->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A stock damage line is immutable.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Stock damage lines are permanent.');
        });
    }

    /**
     * @return BelongsTo<StockDamage, $this>
     */
    public function stockDamage(): BelongsTo
    {
        return $this->belongsTo(StockDamage::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }
}
