<?php

namespace App\Models;

use App\Enums\FinancialYearClosingStatus;
use Database\Factories\FinancialYearClosingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One financial year closed into retained earnings (App\Actions\Accounting\CloseFinancialYear).
 * Its closing journal names it as its source, which is how the profit and loss statement knows to
 * leave closing entries out. Immutable except for the reversal audit fields; never deleted.
 *
 * @property Carbon $year_start_date
 * @property Carbon $year_end_date
 * @property string $net_profit
 * @property FinancialYearClosingStatus $status
 */
class FinancialYearClosing extends Model
{
    /** @use HasFactory<FinancialYearClosingFactory> */
    use HasFactory;

    /** The only fields that may change once written: the reversal's audit trail. */
    private const REVERSAL = ['status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'updated_at'];

    protected $fillable = [
        'year_start_date', 'year_end_date', 'net_profit', 'retained_earnings_account_id', 'journal_entry_id',
        'reversal_journal_entry_id', 'status', 'created_by', 'posted_at', 'reversed_by', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'year_start_date' => 'date',
            'year_end_date' => 'date',
            'net_profit' => 'decimal:6',
            'status' => FinancialYearClosingStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $closing): void {
            if (array_diff(array_keys($closing->getDirty()), self::REVERSAL) !== []) {
                throw new LogicException('A year-end close cannot be edited. Reverse it instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('A year-end close is never deleted.'));
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
