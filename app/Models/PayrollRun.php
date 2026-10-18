<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PayrollDocumentStatus;
use App\Support\DecimalMath;
use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One month's salaries, paid — `Dr wages / Cr employee advances / Cr cash or bank`.
 *
 * Immutable once written, apart from the reversal audit trail. Totals are never stored; they
 * are the sum of the lines.
 */
class PayrollRun extends Model
{
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;

    protected $fillable = [
        'payroll_number',
        'period_start',
        'period_end',
        'pay_date',
        'fiscal_period_id',
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
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
            'status' => PayrollDocumentStatus::class,
            'payment_method' => PaymentMethod::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($run->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A paid payroll is immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Payrolls are permanent. Reverse one instead.');
        });
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * @return HasMany<EmployeeAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
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
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * The sum of one money column across the lines. Computed, never stored.
     *
     * @param  'gross_pay'|'advances_deducted'|'net_pay'  $column
     */
    public function total(string $column): string
    {
        return DecimalMath::money($this->lines->reduce(
            fn (string $carry, PayrollLine $line): string => DecimalMath::add($carry, (string) $line->{$column}),
            DecimalMath::zero(),
        ));
    }
}
