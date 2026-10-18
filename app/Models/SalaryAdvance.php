<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PayrollDocumentStatus;
use Database\Factories\SalaryAdvanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Money given to an employee before payday — `Dr employee advances / Cr cash or bank`.
 *
 * Immutable once written, apart from the reversal audit trail. Taken back in full from the next
 * salary paid; what remains is derived, never stored on this row or on the employee.
 */
class SalaryAdvance extends Model
{
    /** @use HasFactory<SalaryAdvanceFactory> */
    use HasFactory;

    protected $fillable = [
        'advance_number',
        'employee_id',
        'advance_date',
        'amount',
        'fiscal_period_id',
        'cash_bank_account_id',
        'payment_method',
        'reference',
        'note',
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
            'advance_date' => 'date',
            'amount' => 'decimal:2',
            'status' => PayrollDocumentStatus::class,
            'payment_method' => PaymentMethod::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $advance): void {
            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($advance->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A salary advance is immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Salary advances are permanent. Reverse one instead.');
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
}
