<?php

namespace App\Models;

use App\Enums\LoanDocumentStatus;
use App\Enums\LoanFunding;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A loan the factory owes. The money figures are immutable once written; the reminder details
 * (lender name, purpose, usual installment, due day, notes) may be corrected. No balance is
 * stored — see App\Services\Loans\LoanBalances. See docs/LOANS.md.
 */
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory;

    /** What may change after recording: descriptions and the reminder schedule, never money. */
    public const EDITABLE = ['lender', 'purpose', 'has_interest', 'installment_amount', 'due_day', 'notes'];

    protected $fillable = [
        'loan_number',
        'lender',
        'purpose',
        'start_date',
        'funding',
        'amount',
        'cash_bank_account_id',
        'has_interest',
        'installment_amount',
        'due_day',
        'notes',
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
            'start_date' => 'date',
            'funding' => LoanFunding::class,
            'amount' => 'decimal:6',
            'has_interest' => 'boolean',
            'installment_amount' => 'decimal:6',
            'due_day' => 'integer',
            'status' => LoanDocumentStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $loan): void {
            $allowed = [...self::EDITABLE, 'status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];

            if ($loan->getOriginal('journal_entry_id') === null) {
                $allowed[] = 'journal_entry_id';
            }

            $changed = array_diff(array_keys($loan->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A loan\'s money figures are immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Loans are permanent. Reverse one instead.');
        });
    }

    /**
     * @return HasMany<LoanPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class);
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
