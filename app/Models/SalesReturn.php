<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SalesReturnSettlement;
use App\Enums\SalesReturnStatus;
use App\Support\DecimalMath;
use Database\Factories\SalesReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Goods a customer brought back, credited at the price charged and settled by reducing an
 * invoice, an exchange or money back. Immutable once written, apart from its ledger pointers
 * (written once, on creation) and the reversal audit trail. See docs/ACCOUNTING_RULES.md §32.
 */
class SalesReturn extends Model
{
    /** @use HasFactory<SalesReturnFactory> */
    use HasFactory;

    protected $fillable = [
        'return_number',
        'return_date',
        'customer_id',
        'delivery_line_id',
        'product_id',
        'warehouse_id',
        'lot_id',
        'good_quantity',
        'damaged_quantity',
        'secondary_quantity',
        'unit_price',
        'credit_amount',
        'unit_cost',
        'good_cost',
        'damaged_cost',
        'settlement',
        'original_invoice_id',
        'credit_invoice_id',
        'cash_bank_account_id',
        'payment_method',
        'reference',
        'reason',
        'status',
        'inventory_transaction_id',
        'journal_entry_id',
        'reversal_inventory_transaction_id',
        'reversal_journal_entry_id',
        'created_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'good_quantity' => 'decimal:3',
            'damaged_quantity' => 'decimal:3',
            'secondary_quantity' => 'decimal:3',
            'unit_price' => 'decimal:6',
            'credit_amount' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'good_cost' => 'decimal:6',
            'damaged_cost' => 'decimal:6',
            'settlement' => SalesReturnSettlement::class,
            'payment_method' => PaymentMethod::class,
            'status' => SalesReturnStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $return): void {
            $allowed = ['status', 'reversal_inventory_transaction_id', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];

            // The ledger pointers are written once, straight after creation.
            foreach (['inventory_transaction_id', 'journal_entry_id'] as $pointer) {
                if ($return->getOriginal($pointer) === null) {
                    $allowed[] = $pointer;
                }
            }

            $changed = array_diff(array_keys($return->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A sales return is immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Sales returns are permanent. Reverse one instead.');
        });
    }

    /** The goods returned, good and damaged together. */
    public function totalQuantity(): string
    {
        return DecimalMath::quantity(DecimalMath::add((string) $this->good_quantity, (string) $this->damaged_quantity));
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<DeliveryLine, $this>
     */
    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryLine::class);
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
        return $this->belongsTo(InventoryLot::class);
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'original_invoice_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function creditInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'credit_invoice_id');
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
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
