<?php

namespace App\Services\Accounting;

use App\Models\AccountingSetting;
use App\Models\FiscalPeriod;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The management dashboard.
 *
 * Every number here is fetched from the service that owns it — the profit and loss statement,
 * the receivables and payables services, the cash book, the inventory valuation. **Nothing is
 * recalculated.** A dashboard that computes its own version of a figure is how two screens
 * start disagreeing, and the one people glance at is the one they will believe.
 *
 * The period is always explicit. The screen may offer a convenient default, but this service
 * never assumes "the current month" — an accounting period is a business fact, not a
 * calendar convenience. See docs/ACCOUNTING_RULES.md §27.
 */
class FinanceDashboardService
{
    private const TOP_N = 5;

    /** How many months the trend chart shows. */
    private const TREND_MONTHS = 6;

    public function __construct(
        private readonly ProfitLossService $profitLoss,
        private readonly BalanceSheetService $balanceSheet,
        private readonly AccountsReceivableService $receivables,
        private readonly AccountsPayableService $payables,
        private readonly CashBankBookService $cashBank,
        private readonly InventoryValuationReportService $inventory,
        private readonly ProductProfitabilityService $profitability,
        private readonly AccountingBalanceService $balances,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?FiscalPeriod $period = null,
    ): array {
        $profitLoss = $this->profitLoss->statement($from, $to, $period);
        $asOf = $period?->end_date ?? $to;

        $cash = $this->cashBank->summary($asOf);
        $aging = $this->receivables->aging($asOf);
        $payableAging = $this->payables->aging($asOf);

        return [
            'period' => [
                'from' => $profitLoss['from'],
                'to' => $profitLoss['to'],
                'name' => $profitLoss['period'],
            ],
            'performance' => [
                'revenue' => $profitLoss['revenue']['total'],
                'cost_of_sales' => $profitLoss['cost_of_sales']['total'],
                'gross_profit' => $profitLoss['gross_profit'],
                'gross_margin_percent' => $profitLoss['gross_margin_percent'],
                'operating_expenses' => $profitLoss['operating_expenses']['total'],
                'net_profit' => $profitLoss['net_profit'],
            ],
            'position' => [
                'cash_total' => $cash['cash_total'],
                'bank_total' => $cash['bank_total'],
                'cash_and_bank' => $cash['combined_total'],
                'receivables' => $aging['totals']['total'],
                'payables' => $payableAging['totals']['total'],
                'inventory_value' => $this->inventory->report()['value_total'],
                // What is still owed on loans and what fixed assets cost — each read from its own
                // ledger account (accounting settings); null when that account is not set up.
                'loans_owed' => $this->settingBalance('loansAccount', $asOf),
                'fixed_assets' => $this->settingBalance('fixedAssetsAccount', $asOf),
                'current_earnings' => $this->balanceSheet->statement($asOf)['current_earnings'],
            ],
            'receivables' => $this->agingSummary($aging, 'customer'),
            'payables' => $this->agingSummary($payableAging, 'supplier'),
            'top_products' => array_slice($this->profitability->report($from, $to)['rows'], 0, self::TOP_N),
            'top_customers' => $this->topCounterparties($aging['rows'], 'customer'),
            'top_suppliers' => $this->topCounterparties($payableAging['rows'], 'supplier'),
        ];
    }

    /**
     * Revenue, costs and net profit for each calendar month, oldest first, ending with the
     * month that contains $end — the figures behind the dashboard's trend chart.
     *
     * Each month is read from the profit and loss statement for that month, never recalculated;
     * "costs" is the statement's cost of sales plus its operating expenses.
     *
     * @return list<array{month: string, from: string, to: string, revenue: string, costs: string, net_profit: string}>
     */
    public function monthlyTrend(CarbonInterface $end, int $months = self::TREND_MONTHS): array
    {
        $trend = [];
        $last = Carbon::instance($end)->startOfMonth();

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $from = $last->copy()->subMonthsNoOverflow($offset);
            $to = $from->copy()->endOfMonth()->startOfDay();
            $statement = $this->profitLoss->statement($from, $to);

            $trend[] = [
                'month' => $from->format('Y-m'),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'revenue' => $statement['revenue']['total'],
                'costs' => DecimalMath::cost(DecimalMath::add(
                    $statement['cost_of_sales']['total'],
                    $statement['operating_expenses']['total'],
                )),
                'net_profit' => $statement['net_profit'],
            ];
        }

        return $trend;
    }

    /**
     * An aging report condensed to its buckets and an overdue total.
     *
     * The buckets come straight from the AR/AP service, so the dashboard and the aging screen
     * can never disagree about what is overdue.
     *
     * @param  array{rows: list<array<string, mixed>>, totals: array<string, string>, as_of: string}  $aging
     * @return array<string, mixed>
     */
    private function agingSummary(array $aging, string $kind): array
    {
        $totals = $aging['totals'];

        // Everything past its due date — every bucket except current.
        $overdue = DecimalMath::zero();

        foreach (['days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'] as $bucket) {
            $overdue = DecimalMath::add($overdue, $totals[$bucket] ?? DecimalMath::zero());
        }

        return [
            'kind' => $kind,
            'total' => $totals['total'],
            'current' => $totals['current'],
            'days_1_30' => $totals['days_1_30'],
            'days_31_60' => $totals['days_31_60'],
            'days_61_90' => $totals['days_61_90'],
            'days_90_plus' => $totals['days_90_plus'],
            'overdue' => DecimalMath::cost($overdue),
            'as_of' => $aging['as_of'],
        ];
    }

    /**
     * The largest outstanding balances, biggest first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function topCounterparties(array $rows, string $kind): array
    {
        usort($rows, fn (array $a, array $b): int => DecimalMath::compare($b['total'], $a['total']));

        return array_map(fn (array $row): array => [
            'id' => $row["{$kind}_id"],
            'code' => $row["{$kind}_code"],
            'name' => $row["{$kind}_name"],
            'outstanding' => $row['total'],
        ], array_slice($rows, 0, self::TOP_N));
    }

    /**
     * The reporting window a request asked for, with the screen's convenient default.
     *
     * A chosen fiscal period states its own window. Explicit dates are used as given. Only
     * when neither is supplied does the default window apply — a UI convenience, not an
     * accounting rule. Shared by the web dashboard and the API so both open on the same period.
     *
     * @return array{period: FiscalPeriod|null, from: Carbon|null, to: Carbon|null}
     */
    public function resolveWindow(?int $periodId, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $period = $periodId === null ? null : FiscalPeriod::find($periodId);
        $default = $this->defaultWindow();

        $from ??= $period === null ? Carbon::parse($default['from']) : null;
        $to ??= $period === null ? Carbon::parse($default['to']) : null;

        return [
            'period' => $period,
            'from' => $from === null ? null : Carbon::parse($from),
            'to' => $to === null ? null : Carbon::parse($to),
        ];
    }

    /**
     * A sensible window for the screen to open on, with no business meaning attached.
     *
     * Prefers the open fiscal period containing today, because that is a real accounting
     * fact. Falls back to the current month only as a UI convenience — the service itself
     * always takes explicit dates.
     *
     * @return array{from: string, to: string, fiscal_period_id: int|null}
     */
    public function defaultWindow(): array
    {
        $today = BusinessClock::today();
        $period = FiscalPeriod::openFor($today);

        if ($period !== null) {
            return [
                'from' => $period->start_date->toDateString(),
                'to' => $period->end_date->toDateString(),
                'fiscal_period_id' => $period->id,
            ];
        }

        return [
            'from' => $today->copy()->startOfMonth()->toDateString(),
            'to' => $today->copy()->endOfMonth()->toDateString(),
            'fiscal_period_id' => null,
        ];
    }

    /**
     * A configured account's balance as a statement shows it, or null when it is not set up.
     */
    private function settingBalance(string $relation, ?CarbonInterface $asOf): ?string
    {
        $account = AccountingSetting::current()?->{$relation};

        return $account === null ? null : $this->balances->balance($account, $asOf === null ? null : Carbon::instance($asOf));
    }
}
