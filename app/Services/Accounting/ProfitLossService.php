<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\FiscalPeriod;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

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
