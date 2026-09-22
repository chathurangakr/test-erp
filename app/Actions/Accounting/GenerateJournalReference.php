<?php

namespace App\Actions\Accounting;

use App\Models\JournalEntry;
use App\Support\DailyDocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Journal numbers: JV-YYYYMMDD-000001.
 *
 * Reuses the shared daily sequence, so the concurrency handling — a locked read of the
 * current maximum inside the caller's transaction — exists in exactly one place. Like every
 * other reference in this system, the number encodes no business meaning.
 */
class GenerateJournalReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forJournalEntry(?CarbonInterface $date = null): string
    {
        return $this->sequence->next(
            (string) config('accounting.journal_prefix', 'JV'),
            JournalEntry::class,
            'journal_number',
            // This application dates in CarbonImmutable; the shared sequence takes the
            // mutable Illuminate type, so normalise once here rather than at every caller.
            $date === null ? null : Carbon::instance($date),
        );
    }
}
