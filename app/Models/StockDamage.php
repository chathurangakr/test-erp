<?php

namespace App\Models;

use App\Enums\StockDamageStatus;
use Database\Factories\StockDamageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Damaged stock written off — `Dr stock damage and losses / Cr inventory`, at the value the
 * ledger charged.
 *
 * Immutable once written, apart from the reversal audit trail. See docs/INVENTORY_RULES.md §17.
 */
class StockDamage extends Model
{
    /** @use HasFactory<StockDamageFactory> */
    use HasFactory;

    protected $fillable = [
        'damage_number',
        'damage_date',
        'warehouse_id',
        'reason',
        'notes',
        'status',
        'inventory_transaction_id',
        'reversal_inventory_transaction_id',
        'created_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'damage_date' => 'date',
            'status' => StockDamageStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $damage): void {
            // The ledger transaction is attached once, straight after creation.
            $allowed = $damage->getOriginal('inventory_transaction_id') === null
                ? ['inventory_transaction_id', 'updated_at']
                : ['status', 'reversal_inventory_transaction_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($damage->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A stock damage record is immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Stock damage records are permanent. Reverse one instead.');
        });
    }

    /**
     * @return HasMany<StockDamageLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockDamageLine::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'reversal_inventory_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
