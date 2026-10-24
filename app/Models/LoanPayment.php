<?php

namespace App\Models;

use App\Enums\LoanDocumentStatus;
use App\Enums\PaymentMethod;
use Database\Factories\LoanPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One installment paid on a loan — what was actually paid, how much of it was interest, and
 * the rest that reduced the loan. Immutable; corrected by reversal. See docs/LOANS.md.
 */
class LoanPayment extends Model
{
    /** @use HasFactory<LoanPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_number',
        'loan_id',
        'payment_date',
        'amount',
        'interest_amount',
        'principal_amount',
        'cash_bank_account_id',
        'payment_method',
        'reference',
        'status',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'created_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:6',
            'interest_amount' => 'decimal:6',
            'principal_amount' => 'decimal:6',
            'payment_method' => PaymentMethod::class,
            'status' => LoanDocumentStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];

            if ($payment->getOriginal('journal_entry_id') === null) {
                $allowed[] = 'journal_entry_id';
            }

            $changed = array_diff(array_keys($payment->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A loan payment is immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Loan payments are permanent. Reverse one instead.');
        });
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
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
