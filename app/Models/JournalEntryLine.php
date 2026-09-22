<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Support\DecimalMath;
use Database\Factories\JournalEntryLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One side of one journal entry.
 *
 * A line is a debit OR a credit, never both and never neither, and both columns hold
 * POSITIVE amounts. Keeping them separate makes an unbalanced entry a total that does not
 * match, rather than a sign convention someone has to remember — and it is what the trial
 * balance reads directly.
 *
 * Lines of a posted entry are immutable, so posted history cannot be edited from underneath
 * the header.
 */
class JournalEntryLine extends Model
{
    /** @use HasFactory<JournalEntryLineFactory> */
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'description',
        'debit',
        'credit',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:6',
            'credit' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            if ($line->entry?->status === JournalEntryStatus::Draft) {
                return;
            }

            throw new RuntimeException(
                'The lines of a posted journal entry are immutable. Reverse the entry instead.'
            );
        });

        static::deleting(function (self $line): void {
            if ($line->entry !== null && $line->entry->status !== JournalEntryStatus::Draft) {
                throw new RuntimeException(
                    'The lines of a posted journal entry are permanent.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isDebit(): bool
    {
        return DecimalMath::isPositive(DecimalMath::of($this->debit));
    }

    /**
     * The line's effect on the account, as debits minus credits.
     */
    public function signedAmount(): string
    {
        return DecimalMath::cost(
            DecimalMath::sub(DecimalMath::of($this->debit), DecimalMath::of($this->credit))
        );
    }
}
