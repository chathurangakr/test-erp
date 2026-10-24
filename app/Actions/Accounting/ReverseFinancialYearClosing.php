<?php

namespace App\Actions\Accounting;

use App\Enums\FinancialYearClosingStatus;
use App\Models\FinancialYearClosing;
use App\Services\Accounting\FinancialYears;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a year-end close: its journal is reversed at the exact original amounts, dated the SAME
 * year-end day (never today, which would put last year's profit into this year), so the year's
 * last fiscal period must still be open. Only the latest close may be undone — years are closed
 * and reopened in order. See docs/ACCOUNTING_RULES.md §24.
 */
class ReverseFinancialYearClosing
{
    public function __construct(
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly FinancialYears $years,
    ) {}

    public function handle(FinancialYearClosing $closing, ?int $userId = null): FinancialYearClosing
    {
        return DB::transaction(function () use ($closing, $userId): FinancialYearClosing {
            $locked = FinancialYearClosing::query()->whereKey($closing->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== FinancialYearClosingStatus::Posted) {
                throw ValidationException::withMessages(['status' => __('This year-end close has already been reversed.')]);
            }

            if ($this->years->lastClosing()?->isNot($locked)) {
                throw ValidationException::withMessages(['status' => __('Only the latest year-end close can be reversed. Reverse the later year first.')]);
            }

            $reversal = $locked->journalEntry === null ? null : $this->reverseJournal->handle($locked->journalEntry, $userId, [
                'journal_date' => $locked->year_end_date->toDateString(),
            ]);

            $locked->update([
                'status' => FinancialYearClosingStatus::Reversed,
                'reversal_journal_entry_id' => $reversal?->id,
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
