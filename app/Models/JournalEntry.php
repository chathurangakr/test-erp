<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Support\DecimalMath;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A journal entry.
 *
 * A DRAFT affects nothing. Posting freezes the document: a posted entry is immutable and is
 * corrected by a reversing entry, never by editing — the same rule the goods receipt,
 * delivery and production order models already enforce.
 */
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'journal_number',
        'journal_date',
        'fiscal_period_id',
        'source_type',
        'source_id',
        'description',
        'status',
        'reversal_of_id',
        'reversed_by_id',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by_user_id',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => JournalEntryStatus::class,
            'journal_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * A posted entry is frozen. Only the audit fields the reversal action writes may change;
     * everything else must go through a reversing entry.
     */
    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            $original = $entry->getOriginal('status');
            $wasPosted = $original === JournalEntryStatus::Posted->value
                || $original === JournalEntryStatus::Posted;

            if (! $wasPosted) {
                return;
            }

            $allowed = ['status', 'reversed_by_id', 'reversed_at', 'reversed_by_user_id', 'updated_at'];
            $changed = array_diff(array_keys($entry->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted journal entry is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $entry): void {
            if ($entry->status !== JournalEntryStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft journal entry may be deleted. Posted accounting history is permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        // In the order they were written — never left to whichever index the database reads.
        return $this->hasMany(JournalEntryLine::class)->orderBy('id');
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
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
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
    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }

    public function debitTotal(): string
    {
        return DecimalMath::cost($this->lines->reduce(
            fn (string $carry, JournalEntryLine $line): string => DecimalMath::add($carry, DecimalMath::of($line->debit)),
            DecimalMath::zero(),
        ));
    }

    public function creditTotal(): string
    {
        return DecimalMath::cost($this->lines->reduce(
            fn (string $carry, JournalEntryLine $line): string => DecimalMath::add($carry, DecimalMath::of($line->credit)),
            DecimalMath::zero(),
        ));
    }

    /**
     * Debits minus credits. Zero on every entry that may be posted.
     */
    public function difference(): string
    {
        return DecimalMath::cost(DecimalMath::sub($this->debitTotal(), $this->creditTotal()));
    }

    public function isBalanced(): bool
    {
        return DecimalMath::isZero($this->difference());
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }

    /**
     * @param  Builder<JournalEntry>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('journal_number', 'like', '%'.$term.'%')
                ->orWhere('description', 'like', '%'.$term.'%');
        });
    }
}
