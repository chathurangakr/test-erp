<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Support\DecimalMath;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Delivery / dispatch note.
 *
 * A DRAFT moves no stock. Posting issues SALES_ISSUE movements and freezes the document:
 * a posted delivery is immutable and is corrected by reversal, never by editing.
 */
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'delivery_number',
        'customer_id',
        'sales_order_id',
        'warehouse_id',
        'delivery_date',
        'customer_reference',
        'vehicle_number',
        'driver_name',
        'notes',
        'status',
        'inventory_transaction_id',
        'reversal_inventory_transaction_id',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'delivery_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * A posted delivery is frozen. Only the audit fields written by the reversal action may
     * change after posting; everything else must go through a reversal.
     */
    protected static function booted(): void
    {
        static::updating(function (self $delivery): void {
            $original = $delivery->getOriginal('status');
            $wasPosted = $original === DeliveryStatus::Posted->value
                || $original === DeliveryStatus::Posted;

            if (! $wasPosted) {
                return;
            }

            $allowed = [
                'status',
                'reversal_inventory_transaction_id',
                'reversed_at',
                'reversed_by',
                'updated_at',
            ];
            $changed = array_diff(array_keys($delivery->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted delivery is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $delivery): void {
            if ($delivery->status !== DeliveryStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft delivery may be deleted. Posted deliveries are permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<DeliveryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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

    public function salesTotal(): string
    {
        return DecimalMath::cost($this->lines->reduce(
            fn (string $carry, DeliveryLine $line): string => DecimalMath::add($carry, DecimalMath::of($line->sales_value)),
            DecimalMath::zero(),
        ));
    }

    /** Total cost of goods sold. Zero until the delivery is posted. */
    public function costOfGoodsSold(): string
    {
        return DecimalMath::cost($this->lines->reduce(
            fn (string $carry, DeliveryLine $line): string => DecimalMath::add(
                $carry,
                DecimalMath::of($line->cost_of_goods_sold ?? '0'),
            ),
            DecimalMath::zero(),
        ));
    }

    /** Gross profit = sales value - cost of goods sold. */
    public function grossProfit(): string
    {
        return DecimalMath::cost(DecimalMath::sub($this->salesTotal(), $this->costOfGoodsSold()));
    }

    /**
     * Gross margin as a percentage of sales value.
     *
     * A sale worth nothing has no margin to express, so zero sales value yields 0 rather
     * than a division error.
     */
    public function grossMarginPercent(): string
    {
        $sales = $this->salesTotal();

        if (DecimalMath::isZero($sales)) {
            return DecimalMath::round('0', 2);
        }

        return DecimalMath::round(
            DecimalMath::mul(DecimalMath::div($this->grossProfit(), $sales), '100'),
            2,
        );
    }

    public function isReversed(): bool
    {
        return $this->reversal_inventory_transaction_id !== null;
    }

    /**
     * @param  Builder<Delivery>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('delivery_number', 'like', "%{$term}%")
                ->orWhere('customer_reference', 'like', "%{$term}%")
                ->orWhere('vehicle_number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%"));
        });
    }
}
