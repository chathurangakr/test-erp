<?php

namespace App\Models;

use App\Enums\FiscalPeriodStatus;
use App\Enums\JournalEntryStatus;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\FiscalPeriodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An accounting period.
 *
 * Journals may only be posted into an OPEN period whose range contains the journal date, so
 * closing a period freezes what has been reported on it — including against reversals, which
 * must find their own open period rather than reaching back into a closed one.
 */
class FiscalPeriod extends Model
{
    /** @use HasFactory<FiscalPeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => FiscalPeriodStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === FiscalPeriodStatus::Open;
    }

    public function contains(Carbon|string $date): bool
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /**
     * Whether the period still holds unposted work.
     *
     * A period is not closed while a draft sits in it: the draft would become unpostable and
     * invisible to every report, which is a worse outcome than refusing to close.
     */
    public function hasDraftEntries(): bool
    {
        return $this->journalEntries()
            ->where('status', JournalEntryStatus::Draft)
            ->exists();
    }

    /**
     * The open period a given date belongs to, or null.
     */
    /**
     * The day an OPENING entry is booked on — something the factory already had before it was
     * entered (an asset already owned, a loan already running). Its own date when an open period
     * covers it; otherwise, such as a date before the books started, the day it is entered. The
     * real date stays on the document; only the ledger date moves. See docs/ACCOUNTING_RULES.md §7.
     */
    public static function openingEntryDate(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::parse($date->toDateString());

        return self::openFor($date->toDateString()) !== null ? $date : BusinessClock::today();
    }

    public static function openFor(Carbon|string $date): ?self
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return self::query()
            ->where('status', FiscalPeriodStatus::Open)
            ->whereOnOrBefore('start_date', $date->toDateString())
            ->whereOnOrAfter('end_date', $date->toDateString())
            ->orderBy('start_date')
            ->first();
    }

    /**
     * @param  Builder<FiscalPeriod>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $query->where('name', 'like', '%'.trim($term).'%');
    }
}
