<?php

namespace App\Models;

use App\Enums\CustomerBagMovementKind;
use App\Enums\CustomerBagMovementStatus;
use Database\Factories\CustomerBagMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Returnable bags given to, returned by or lost by a customer.
 *
 * Immutable once written, apart from the reversal audit trail. What a customer holds is derived
 * from these rows (CustomerBagBalances), never stored. See docs/INVENTORY_RULES.md §18.
 */
class CustomerBagMovement extends Model
{
    /** @use HasFactory<CustomerBagMovementFactory> */
    use HasFactory;

    protected $fillable = [
        'movement_number',
        'movement_date',
        'kind',
        'customer_id',
        'product_id',
        'warehouse_id',
        'quantity',
        'note',
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
            'movement_date' => 'date',
            'kind' => CustomerBagMovementKind::class,
            'status' => CustomerBagMovementStatus::class,
            'quantity' => 'decimal:3',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $movement): void {
            // The ledger transaction is attached once, straight after creation.
            $allowed = $movement->getOriginal('inventory_transaction_id') === null
                ? ['inventory_transaction_id', 'updated_at']
                : ['status', 'reversal_inventory_transaction_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($movement->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A bag movement is immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Bag movements are permanent. Reverse one instead.');
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
