<?php

namespace App\Actions\Accounting;

use App\Enums\AccountType;
use App\Enums\FinancialYearClosingStatus;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\FinancialYearClosing;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\FinancialYears;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a finished financial year into retained earnings with ONE journal, dated the year's last
 * day:
 *
 *   every revenue, cost-of-sales and expense account's balance at the year end, reversed
 *   (so each is left at zero), and the difference — the profit, or a loss — to retained
 *   earnings (config('accounting.retained_earnings_code')).
 *
 * The balances are cumulative and include earlier closes, so whatever is still unclosed at the
 * year end is closed, exactly once. The profit and loss statement leaves closing journals out, so
 * it still shows the year's real figures; the balance sheet counts them, so its current earnings
 * start again from the close. See docs/ACCOUNTING_RULES.md §24.
 *
 * Refused: a year not yet ended; a year already closed; a year EARLIER than one already closed
 * (years close in order); and when the year's last day is in no open fiscal period — close the
 * year before closing its last month. A year with nothing to close is recorded with no journal.
 */
class CloseFinancialYear
{
    public function __construct(
        private readonly FinancialYears $years,
        private readonly AccountingBalanceService $balances,
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
    ) {}

    public function handle(string $yearEnd, ?int $userId = null): FinancialYearClosing
    {
        [$start, $end] = $this->years->containing(CarbonImmutable::parse($yearEnd));

        if (! $end->isSameDay(CarbonImmutable::parse($yearEnd))) {
            throw ValidationException::withMessages(['year_end_date' => __('A financial year ends on :date.', ['date' => $end->toDateString()])]);
        }

        if (! BusinessClock::today()->greaterThan($end)) {
            throw ValidationException::withMessages(['status' => __('The financial year ending :date can be closed from the next day.', ['date' => $end->toDateString()])]);
        }

        return DB::transaction(function () use ($start, $end, $userId): FinancialYearClosing {
            // One close at a time: a second click waits, then finds the year closed.
            FinancialYearClosing::query()->lockForUpdate()->get(['id']);

            if ($this->years->closingFor($end) !== null) {
                throw ValidationException::withMessages(['status' => __('The financial year ending :date is already closed.', ['date' => $end->toDateString()])]);
            }

            $later = $this->years->lastClosing();

            if ($later !== null && $later->year_end_date->greaterThan($end)) {
                throw ValidationException::withMessages(['status' => __('A later year (ending :date) is already closed. Years are closed in order.', ['date' => $later->year_end_date->toDateString()])]);
            }

            $retained = Account::query()->where('code', (string) config('accounting.retained_earnings_code', '3200'))->first()
                ?? throw ValidationException::withMessages(['status' => __('The retained earnings account is missing from the chart of accounts.')]);

            $lines = $this->closingLines($end);
            $profit = DecimalMath::zero();

            foreach ($lines as [, $debit, $credit]) {
                // Emptying revenue (a debit) adds to profit; emptying a cost (a credit) takes away.
                $profit = DecimalMath::add($profit, DecimalMath::sub($debit, $credit));
            }

            $profit = DecimalMath::cost($profit);
            $journal = null;

            if ($lines !== []) {
                $period = FiscalPeriod::openFor($end) ?? throw ValidationException::withMessages([
                    'status' => __('The closing entry is dated :date, but no open fiscal period covers it. Close the year before closing its last month.', ['date' => $end->toDateString()]),
                ]);

                $lines[] = DecimalMath::isNegative($profit)
                    ? [$retained->id, DecimalMath::cost(ltrim($profit, '-')), DecimalMath::zero()]
                    : [$retained->id, DecimalMath::zero(), $profit];
            }

            $closing = FinancialYearClosing::create([
                'year_start_date' => $start->toDateString(),
                'year_end_date' => $end->toDateString(),
                'net_profit' => $profit,
                'retained_earnings_account_id' => $retained->id,
                'status' => FinancialYearClosingStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            if ($lines !== []) {
                $journal = $this->journal($closing, $end, $period, $lines, $userId);
                $closing->forceFill(['journal_entry_id' => $journal->id]);
                FinancialYearClosing::withoutEvents(fn () => $closing->save());
            }

            return $closing->refresh();
        });
    }

    /**
     * Each profit and loss account's balance at the year end, reversed: [account id, debit, credit].
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function closingLines(CarbonImmutable $end): array
    {
        $totals = $this->balances->totalsByAccount(Carbon::parse($end->toDateString()));
        $accounts = Account::query()
            ->whereIn('id', $totals->keys()->all())
            ->whereIn('account_type', [AccountType::Revenue->value, AccountType::CostOfSales->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();
        $lines = [];

        foreach ($accounts as $account) {
            $net = $totals[$account->id]['net'];

            if (DecimalMath::isZero($net)) {
                continue;
            }

            $lines[] = DecimalMath::isPositive($net)
                ? [$account->id, DecimalMath::zero(), $net]
                : [$account->id, DecimalMath::cost(ltrim($net, '-')), DecimalMath::zero()];
        }

        return $lines;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $lines
     */
    private function journal(FinancialYearClosing $closing, CarbonImmutable $end, FiscalPeriod $period, array $lines, ?int $userId): JournalEntry
    {
        $entry = JournalEntry::create([
            'journal_number' => $this->references->forJournalEntry($end),
            'journal_date' => $end->toDateString(),
            'fiscal_period_id' => $period->id,
            'source_type' => FinancialYearClosing::class,
            'source_id' => $closing->id,
            'description' => RecordText::of('Year-end closing: profit for :from to :to moved to retained earnings', [
                'from' => $closing->year_start_date->toDateString(),
                'to' => $end->toDateString(),
            ]),
            'status' => JournalEntryStatus::Draft,
            'created_by' => $userId ?? auth()->id(),
        ]);

        foreach ($lines as [$accountId, $debit, $credit]) {
            $entry->lines()->create(['account_id' => $accountId, 'debit' => $debit, 'credit' => $credit]);
        }

        return $this->post->handle($entry->refresh(), $userId);
    }
}
