<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\FiscalPeriod;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The profit and loss statement.
 *
 *   Revenue        − Cost of Sales      = Gross Profit
 *   Gross Profit   − Operating Expenses = Net Profit
 *
 * Every figure comes from posted journal lines, through `AccountingBalanceService`, so the
 * statement reflects **recognised** revenue. A delivery that has not been invoiced shows its
 * cost of sales here and no revenue, which is correct and is what the unbilled-deliveries
 * report exists to surface.
 *
 * Accounts are classified by `AccountType` alone. **No account code or code prefix appears
 * anywhere in this class**, so a factory that renumbers its chart does not break its
 * accounts.
 *
 * This is not the same figure as operational product profitability, which is built from
 * deliveries and answers a different question. See docs/ACCOUNTING_RULES.md §25.
 */
class ProfitLossService
{
    public function __construct(
        private readonly AccountingBalanceService $balances,
        private readonly AccountTreeService $tree,
    ) {}

    /**
     * The statement for a window, a fiscal period, or all of recorded history.
     *
     * @return array{
     *     revenue: array{rows: list<array<string, mixed>>, total: string},
     *     cost_of_sales: array{rows: list<array<string, mixed>>, total: string},
     *     gross_profit: string,
     *     gross_margin_percent: string,
     *     operating_expenses: array{rows: list<array<string, mixed>>, total: string},
     *     net_profit: string,
     *     from: string|null,
     *     to: string|null,
     *     period: string|null
     * }
     */
    public function statement(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?FiscalPeriod $period = null,
        bool $sinceLastClose = false,
    ): array {
        // A period states its own window; explicit dates are only used when none is chosen.
        // Year-end closing journals are left out — the statement shows what was earned and spent —
        // unless the profit SINCE THE LAST CLOSE is wanted (the balance sheet's current earnings),
        // which is exactly what counting the closings leaves.
        $totals = $this->balances->totalsByAccount(
            $to === null ? null : Carbon::instance($to),
            $period,
            $from === null ? null : Carbon::instance($from),
            withYearEndClosings: $sinceLastClose,
        );

        $revenue = $this->tree->build([AccountType::Revenue], $totals);
        $costOfSales = $this->tree->build([AccountType::CostOfSales], $totals);
        $expenses = $this->tree->build([AccountType::Expense], $totals);

        $grossProfit = DecimalMath::cost(DecimalMath::sub($revenue['total'], $costOfSales['total']));
        $netProfit = DecimalMath::cost(DecimalMath::sub($grossProfit, $expenses['total']));

        return [
            'revenue' => $revenue,
            'cost_of_sales' => $costOfSales,
            'gross_profit' => $grossProfit,
            'gross_margin_percent' => $this->marginPercent($grossProfit, $revenue['total']),
            'operating_expenses' => $expenses,
            'net_profit' => $netProfit,
            'from' => $period?->start_date?->toDateString() ?? $from?->toDateString(),
            'to' => $period?->end_date?->toDateString() ?? $to?->toDateString(),
            'period' => $period?->name,
        ];
    }

    /**
     * The window to compare a statement with: the same length just before it ('previous') or the
     * same dates a year earlier ('last_year'). Whole months stay whole months — March is compared
     * with February, not with the 28 days before it. See docs/ACCOUNTING_RULES.md §38.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function priorWindow(CarbonInterface $from, CarbonInterface $to, string $mode): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());
        $wholeMonths = $from->day === 1 && $to->isSameDay($to->endOfMonth());

        if ($mode === 'last_year') {
            $priorTo = $to->subYearNoOverflow();

            return [$from->subYearNoOverflow(), $wholeMonths ? $priorTo->endOfMonth() : $priorTo];
        }

        if ($wholeMonths) {
            $months = (int) $from->diffInMonths($to->addDay());

            return [$from->subMonthsNoOverflow($months), $from->subDay()];
        }

        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days), $from->subDay()];
    }

    /**
     * The statement for one window beside an earlier one: every account that moved in either,
     * with both figures and the change. Year-end closings are left out of both, as in statement().
     *
     * @return array{
     *     from: string, to: string, prior_from: string, prior_to: string,
     *     sections: list<array{key: string, label: string, rows: list<array<string, mixed>>, current: string, prior: string, change: string, change_percent: string|null}>,
     *     lines: list<array{key: string, label: string, current: string, prior: string, change: string, change_percent: string|null}>
     * }
     */
    public function compare(CarbonInterface $from, CarbonInterface $to, CarbonInterface $priorFrom, CarbonInterface $priorTo): array
    {
        $current = $this->balances->totalsByAccount(Carbon::instance($to), null, Carbon::instance($from), withYearEndClosings: false);
        $prior = $this->balances->totalsByAccount(Carbon::instance($priorTo), null, Carbon::instance($priorFrom), withYearEndClosings: false);
        $sections = [];
        $totals = [];

        foreach (['revenue' => [AccountType::Revenue, 'Revenue'], 'cost_of_sales' => [AccountType::CostOfSales, 'Cost of sales'], 'operating_expenses' => [AccountType::Expense, 'Operating expenses']] as $key => [$type, $label]) {
            // Built with every account, so both trees share one shape and can be walked side by side.
            $a = $this->tree->build([$type], $current, includeEmpty: true);
            $b = $this->tree->build([$type], $prior, includeEmpty: true);
            $totals[$key] = [$a['total'], $b['total']];
            $sections[] = ['key' => $key, 'label' => __($label), 'rows' => $this->pair($a['rows'], $b['rows'])] + $this->change($a['total'], $b['total']);
        }

        $gross = [DecimalMath::sub($totals['revenue'][0], $totals['cost_of_sales'][0]), DecimalMath::sub($totals['revenue'][1], $totals['cost_of_sales'][1])];
        $net = [DecimalMath::sub($gross[0], $totals['operating_expenses'][0]), DecimalMath::sub($gross[1], $totals['operating_expenses'][1])];

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'prior_from' => $priorFrom->toDateString(),
            'prior_to' => $priorTo->toDateString(),
            'sections' => $sections,
            'lines' => [
                ['key' => 'revenue', 'label' => __('Revenue')] + $this->change(...$totals['revenue']),
                ['key' => 'cost_of_sales', 'label' => __('Cost of sales')] + $this->change(...$totals['cost_of_sales']),
                ['key' => 'gross_profit', 'label' => __('Gross profit')] + $this->change(...$gross),
                ['key' => 'operating_expenses', 'label' => __('Operating expenses')] + $this->change(...$totals['operating_expenses']),
                ['key' => 'net_profit', 'label' => __('Net profit')] + $this->change(...$net),
            ],
        ];
    }

    /**
     * Two same-shaped account trees as one flat list, keeping an account that moved in either.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $prior
     * @return list<array<string, mixed>>
     */
    private function pair(array $current, array $prior): array
    {
        $rows = [];

        foreach ($current as $i => $node) {
            $other = $prior[$i];
            $children = $this->pair($node['children'], $other['children']);

            if ($children === [] && DecimalMath::isZero($node['own_amount']) && DecimalMath::isZero($other['own_amount'])) {
                continue;
            }

            $rows[] = [
                'account_id' => $node['account_id'],
                'code' => $node['code'],
                'name' => $node['name'],
                'depth' => $node['depth'],
                'is_group' => $children !== [],
            ] + $this->change($node['amount'], $other['amount']);
            array_push($rows, ...$children);
        }

        return $rows;
    }

    /**
     * @return array{current: string, prior: string, change: string, change_percent: string|null}
     */
    private function change(string $current, string $prior): array
    {
        $change = DecimalMath::cost(DecimalMath::sub($current, $prior));

        return [
            'current' => DecimalMath::cost($current),
            'prior' => DecimalMath::cost($prior),
            'change' => $change,
            // No percentage against nothing: from zero, any change is "new", not infinite.
            'change_percent' => DecimalMath::isZero($prior) ? null : DecimalMath::round(DecimalMath::mul(DecimalMath::div($change, DecimalMath::isNegative($prior) ? DecimalMath::sub('0', $prior) : $prior), '100'), 1),
        ];
    }

    /**
     * Net profit from account totals already summed (AccountingBalanceService::totalsByAccount) —
     * so the balance sheet reads its current earnings from the same pass over the ledger as its
     * accounts, instead of summing the whole history twice.
     *
     * @param  Collection<int, array{debit: string, credit: string, net: string}>  $totals
     */
    public function netProfitFromTotals(Collection $totals): string
    {
        $revenue = $this->tree->build([AccountType::Revenue], $totals)['total'];
        $costOfSales = $this->tree->build([AccountType::CostOfSales], $totals)['total'];
        $expenses = $this->tree->build([AccountType::Expense], $totals)['total'];

        return DecimalMath::cost(DecimalMath::sub(DecimalMath::sub($revenue, $costOfSales), $expenses));
    }

    /**
     * Net profit alone, for the dashboard and for the balance sheet's current earnings.
     */
    public function netProfit(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?FiscalPeriod $period = null,
        bool $sinceLastClose = false,
    ): string {
        return $this->statement($from, $to, $period, $sinceLastClose)['net_profit'];
    }

    /**
     * Margin as a percentage, safe at zero revenue.
     *
     * No sales means no margin — zero, never a division error and never a misleading 100%.
     */
    private function marginPercent(string $profit, string $revenue): string
    {
        if (DecimalMath::isZero($revenue)) {
            return DecimalMath::round('0', 2);
        }

        return DecimalMath::round(
            DecimalMath::mul(DecimalMath::div($profit, $revenue), '100'),
            2,
        );
    }
}
