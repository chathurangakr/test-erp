<?php

namespace App\Services\Accounting;

use App\Enums\FinancialYearClosingStatus;
use App\Models\AccountingSetting;
use App\Models\FinancialYearClosing;
use App\Models\JournalEntry;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The financial year: it ends on the last day of `accounting_settings.financial_year_end_month`
 * (March → 1 April to 31 March). Read-only. See docs/ACCOUNTING_RULES.md §24.
 */
class FinancialYears
{
    public function endMonth(): int
    {
        return (int) (AccountingSetting::current()?->financial_year_end_month ?? 3);
    }

    /**
     * The financial year a date falls in.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function containing(CarbonInterface $date): array
    {
        $date = CarbonImmutable::parse($date->toDateString());
        $end = $date->setDate($date->year, $this->endMonth(), 1)->endOfMonth()->startOfDay();

        if ($end->lessThan($date)) {
            $end = $end->addYearNoOverflow()->endOfMonth()->startOfDay();
        }

        return [$end->subYearNoOverflow()->endOfMonth()->addDay()->startOfDay(), $end];
    }

    /** The standing close of the year ending on that day, if any. */
    public function closingFor(CarbonInterface $yearEnd): ?FinancialYearClosing
    {
        return FinancialYearClosing::query()
            ->whereOnDay('year_end_date', $yearEnd->toDateString())
            ->where('status', FinancialYearClosingStatus::Posted)
            ->first();
    }

    /** The most recent standing close, if any. */
    public function lastClosing(): ?FinancialYearClosing
    {
        return FinancialYearClosing::query()
            ->where('status', FinancialYearClosingStatus::Posted)
            ->orderByDesc('year_end_date')
            ->first();
    }

    /**
     * Every financial year from the first journal to the one running today, newest first.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function years(): array
    {
        $first = JournalEntry::query()->min('journal_date');
        $today = BusinessClock::today();
        [$start, $end] = $this->containing($first === null ? $today : CarbonImmutable::parse($first));
        $years = [];

        while ($start->lessThanOrEqualTo($today)) {
            $years[] = [$start, $end];
            [$start, $end] = $this->containing($end->addDay());
        }

        return array_reverse($years);
    }
}
