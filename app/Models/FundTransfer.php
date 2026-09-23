<?php

namespace App\Models;

use App\Enums\FundTransferStatus;
use Database\Factories\FundTransferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Money moved between the factory's own cash and bank accounts.
 *
 *   Dr destination cash / bank
 *       Cr source cash / bank
 *
 * The company is neither richer nor poorer afterwards: combined cash and bank is identical,
 * nothing is earned, nothing is spent, nothing is owed. That is the whole point, and it is
 * why a transfer must never be recorded as an expense or a receipt.
 *
 * A posted transfer is immutable and is corrected by reversal.
 */
class FundTransfer extends Model
{
    /** @use HasFactory<FundTransferFactory> */
    use HasFactory;

    protected $fillable = [
        'transfer_number',
        'transfer_date',
        'fiscal_period_id',
        'from_cash_bank_account_id',
        'to_cash_bank_account_id',
        'amount',
        'reference',
        'description',
        'status',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FundTransferStatus::class,
            'transfer_date' => 'date',
            'amount' => 'decimal:6',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transfer): void {
            $original = $transfer->getOriginal('status');
            $original = $original instanceof FundTransferStatus
                ? $original
                : FundTransferStatus::tryFrom((string) $original);

            if ($original !== FundTransferStatus::Posted) {
                return;
            }

            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($transfer->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted fund transfer is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $transfer): void {
            if ($transfer->status !== FundTransferStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft fund transfer may be deleted. Posted transfers are permanent.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'from_cash_bank_account_id');
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'to_cash_bank_account_id');
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
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
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @param  Builder<FundTransfer>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('transfer_number', 'like', '%'.$term.'%')
                ->orWhere('reference', 'like', '%'.$term.'%')
                ->orWhere('description', 'like', '%'.$term.'%');
        });
    }
}
