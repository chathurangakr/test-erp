<?php

namespace App\Models;

use App\Enums\InventoryTransactionType;
use Database\Factories\InventoryTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Inventory transaction header.
 *
 * POSTED TRANSACTIONS ARE IMMUTABLE. The model refuses updates and deletes outright, so
 * the rule holds no matter which code path is taken — controller, console command, tinker
 * or a future REST API. Corrections are made by posting a reversal.
 * See docs/INVENTORY_RULES.md §9 and §12.
 */
class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'reference',
        'transaction_type',
        'transaction_date',
        'source_type',
        'source_id',
        'reversal_of_id',
        'reversed_by_id',
        'notes',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => InventoryTransactionType::class,
            'transaction_date' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            // The reversal link is the single exception: marking the original as reversed
            // is an append-only audit pointer, not a change to what was posted.
            $changed = array_keys($transaction->getDirty());

            if ($changed !== ['reversed_by_id']) {
                throw new RuntimeException(
                    'Posted inventory transactions are immutable. Post a reversal instead.'
                );
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Posted inventory transactions cannot be deleted. Audit history is permanent.'
            );
        });
    }

    /**
     * @return HasMany<InventoryTransactionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryTransactionLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The transaction this one reverses, if any.
     *
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The reversal that cancelled this transaction, if any.
     *
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }
}
