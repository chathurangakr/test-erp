<?php

namespace App\Models;

use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use Database\Factories\SupplierAdvanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Money paid to a supplier BEFORE their invoice: `Dr supplier advances / Cr cash/bank`. It is applied to
 * their invoices later (SupplierAdvanceApplication).
 * Immutable once written except for the journal link and the reversal trail; never deleted.
 * What is left of it is derived — App\Services\Accounting\AdvanceBalances. See
 * docs/ACCOUNTING_RULES.md §34.
 *
 * @property string $advance_number
 * @property Carbon $advance_date
 * @property string $amount
 * @property PaymentMethod $payment_method
 * @property AdvanceStatus $status
 */
class SupplierAdvance extends Model
{
    /** @use HasFactory<SupplierAdvanceFactory> */
    use HasFactory;

    private const AUDIT = ['journal_entry_id', 'status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'updated_at'];

    protected $fillable = [
        'advance_number', 'supplier_id', 'advance_date', 'amount', 'cash_bank_account_id', 'payment_method',
        'cheque_number', 'cheque_date', 'cheque_bank', 'reference', 'note', 'status', 'journal_entry_id',
        'reversal_journal_entry_id', 'created_by', 'posted_at', 'reversed_by', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'advance_date' => 'date',
            'cheque_date' => 'date',
            'amount' => 'decimal:6',
            'payment_method' => PaymentMethod::class,
            'status' => AdvanceStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $advance): void {
            if (array_diff(array_keys($advance->getDirty()), self::AUDIT) !== []) {
                throw new LogicException('A posted advance cannot be edited. Reverse it instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('An advance is never deleted.'));
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<SupplierAdvanceApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(SupplierAdvanceApplication::class);
    }

    /** @return HasMany<SupplierAdvanceRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(SupplierAdvanceRefund::class);
    }

    /** @return BelongsTo<CashBankAccount, $this> */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
