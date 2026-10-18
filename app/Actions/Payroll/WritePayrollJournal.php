<?php

namespace App\Actions\Payroll;

use App\Actions\Accounting\GenerateJournalReference;
use App\Actions\Accounting\PostJournalEntry;
use App\Enums\JournalEntryStatus;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes and posts the journal for a payroll document — a salary advance or a paid payroll.
 *
 * Lines are given as [account id, debit, credit, description]; zero-value lines are left out,
 * and PostJournalEntry refuses anything that does not balance exactly. Must run inside the
 * caller's transaction, so a document is never paid without its journal.
 */
class WritePayrollJournal
{
    public function __construct(
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
    ) {}

    /**
     * @param  list<array{0: int, 1: string, 2: string, 3: string|null}>  $lines
     */
    public function handle(Model $source, CarbonInterface $date, FiscalPeriod $period, string $description, array $lines, ?int $userId): JournalEntry
    {
        $entry = JournalEntry::create([
            'journal_number' => $this->references->forJournalEntry($date),
            'journal_date' => $date->toDateString(),
            'fiscal_period_id' => $period->id,
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'description' => $description,
            'status' => JournalEntryStatus::Draft,
            'created_by' => $userId ?? auth()->id(),
        ]);

        foreach ($lines as [$accountId, $debit, $credit, $lineDescription]) {
            if (! DecimalMath::isPositive($debit) && ! DecimalMath::isPositive($credit)) {
                continue;
            }

            $entry->lines()->create([
                'account_id' => $accountId,
                'description' => $lineDescription,
                'debit' => DecimalMath::cost($debit),
                'credit' => DecimalMath::cost($credit),
            ]);
        }

        return $this->post->handle($entry->refresh(), $userId);
    }
}
