<?php

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
use App\Support\DecimalMath;
use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Goods receipt (GRN).
 *
 * A DRAFT creates no inventory. Posting writes a PURCHASE_RECEIPT to the ledger and freezes
 * the document: a posted receipt is immutable and is corrected by reversal, never by edit.
 */
class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'grn_number',
        'supplier_id',
        'purchase_order_id',
        'warehouse_id',
        'receipt_date',
        'supplier_delivery_reference',
        'transport_cost',
        'loading_cost',
        'other_cost',
        'notes',
        'status',
        'inventory_transaction_id',
        'reversal_inventory_transaction_id',
        'reversed_at',
        'reversed_by',
        'posted_by',
        'posted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => GoodsReceiptStatus::class,
            'receipt_date' => 'date',
            'transport_cost' => 'decimal:6',
            'loading_cost' => 'decimal:6',
            'other_cost' => 'decimal:6',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * A posted receipt is frozen. Only the audit fields written by the reversal action may
     * change after posting; everything else must go through a reversal.
     */
    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            $original = $receipt->getOriginal('status');
            $wasPosted = $original === GoodsReceiptStatus::Posted->value
                || $original === GoodsReceiptStatus::Posted;

            if (! $wasPosted) {
                return;
            }

            $allowed = ['status', 'reversal_inventory_transaction_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($receipt->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted goods receipt is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $receipt): void {
            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft goods receipt may be deleted. Posted receipts are permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function reversalInventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'reversal_inventory_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
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

    /** Total of the header-level landed costs. */
    public function landedCostTotal(): string
    {
        return DecimalMath::cost(DecimalMath::add(
            DecimalMath::add(DecimalMath::of($this->transport_cost), DecimalMath::of($this->loading_cost)),
            DecimalMath::of($this->other_cost),
        ));
    }

    public function isReversed(): bool
    {
        return $this->reversal_inventory_transaction_id !== null;
    }

    /**
     * @param  Builder<GoodsReceipt>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('grn_number', 'like', "%{$term}%")
                ->orWhere('supplier_delivery_reference', 'like', "%{$term}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%"));
        });
    }
}
