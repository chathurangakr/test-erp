<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SalesCreditNoteKind;
use App\Enums\SalesCreditNoteSettlement;
use App\Enums\SalesCreditNoteStatus;
use Database\Factories\SalesCreditNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A discount or quality allowance on one invoice — no goods come back
 * (App\Actions\Receivables\RecordSalesCreditNote). It either reduces what the invoice is owed
 * (CREDIT) or pays money back from cash or bank (REFUND). Immutable once written except for the
 * reversal audit fields; never deleted. See docs/ACCOUNTING_RULES.md §33.
 *
 * @property string $credit_note_number
 * @property Carbon $credit_date
 * @property SalesCreditNoteKind $kind
 * @property SalesCreditNoteSettlement $settlement
 * @property string $amount
 * @property SalesCreditNoteStatus $status
 */
class SalesCreditNote extends Model
{
    /** @use HasFactory<SalesCreditNoteFactory> */
    use HasFactory;

    /** The only fields that may change once written: the journal link and the reversal trail. */
    private const AUDIT = ['journal_entry_id', 'status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'updated_at'];

    protected $fillable = [
        'credit_note_number', 'credit_date', 'customer_id', 'sales_invoice_id', 'kind', 'settlement', 'cash_bank_account_id', 'payment_method', 'amount', 'reason', 'status',
        'journal_entry_id', 'reversal_journal_entry_id', 'created_by', 'posted_at', 'reversed_by', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'credit_date' => 'date',
            'kind' => SalesCreditNoteKind::class,
            'settlement' => SalesCreditNoteSettlement::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:6',
            'status' => SalesCreditNoteStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $note): void {
            if (array_diff(array_keys($note->getDirty()), self::AUDIT) !== []) {
                throw new LogicException('A posted credit note cannot be edited. Reverse it instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('A credit note is never deleted.'));
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
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
