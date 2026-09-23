<?php

namespace App\Models;

use App\Enums\ExpenseVoucherStatus;
use App\Support\DecimalMath;
use Database\Factories\ExpenseVoucherFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * An expense paid immediately from cash or the bank.
 *
 *   Dr each expense account
 *       Cr the chosen cash / bank account
 *
 * This is **not** a supplier invoice. A supplier may be named for reporting — "who did we
 * buy the fuel from" — but no payable is raised, because nothing is owed: the money left at
 * the same moment the expense was incurred.
 *
 * A posted voucher is immutable. The total is never stored; it is the sum of the lines.
 */
class ExpenseVoucher extends Model
{
    /** @use HasFactory<ExpenseVoucherFactory> */
    use HasFactory;

    protected $fillable = [
        'voucher_number',
        'voucher_date',
        'fiscal_period_id',
        'cash_bank_account_id',
        'supplier_id',
        'payee_name',
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
            'status' => ExpenseVoucherStatus::class,
            'voucher_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $voucher): void {
            $original = $voucher->getOriginal('status');
            $original = $original instanceof ExpenseVoucherStatus
                ? $original
                : ExpenseVoucherStatus::tryFrom((string) $original);

            if ($original !== ExpenseVoucherStatus::Posted) {
                return;
            }

            // The only permitted post-posting writes are the reversal audit trail.
            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($voucher->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted expense voucher is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $voucher): void {
            if ($voucher->status !== ExpenseVoucherStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft expense voucher may be deleted. Posted vouchers are permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<ExpenseVoucherLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseVoucherLine::class);
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * The sum of the lines. Computed, never stored, so it cannot drift from them.
     */
    public function total(): string
    {
        return DecimalMath::cost($this->lines->reduce(
            fn (string $carry, ExpenseVoucherLine $line): string => DecimalMath::add(
                $carry,
                DecimalMath::of($line->amount),
            ),
            DecimalMath::zero(),
        ));
    }

    /**
     * Who the money went to, however it was recorded.
     */
    public function payee(): ?string
    {
        return $this->supplier?->name ?? $this->payee_name;
    }

    /**
     * @param  Builder<ExpenseVoucher>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('voucher_number', 'like', '%'.$term.'%')
                ->orWhere('reference', 'like', '%'.$term.'%')
                ->orWhere('payee_name', 'like', '%'.$term.'%')
                ->orWhere('description', 'like', '%'.$term.'%');
        });
    }
}
