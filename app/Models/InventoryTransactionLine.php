<?php

namespace App\Models;

use App\Enums\InventoryDirection;
use Database\Factories\InventoryTransactionLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A single inventory ledger movement.
 *
 * Quantity is always positive; `direction` decides the sign. Like the header, posted lines
 * are immutable — see docs/INVENTORY_RULES.md §9.
 */
class InventoryTransactionLine extends Model
{
    /** @use HasFactory<InventoryTransactionLineFactory> */
    use HasFactory;

    protected $fillable = [
        'inventory_transaction_id',
        'product_id',
        'warehouse_id',
        'lot_id',
        'direction',
        'quantity',
        'secondary_quantity',
        'unit_cost',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'direction' => InventoryDirection::class,
            // decimal casts keep these as precise strings, never floats.
            'quantity' => 'decimal:3',
            'secondary_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'Posted inventory lines are immutable. Post a reversal instead.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Posted inventory lines cannot be deleted. Audit history is permanent.'
            );
        });
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
