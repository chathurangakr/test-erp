<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The balance sheet, as at a date.
 *
 *   Assets  =  Liabilities  +  Equity  +  Current Earnings
 *
 * That identity holds **exactly, always**, and not by effort: because every posted journal
 * balances, the net balances of all six account types sum to zero, and rearranging that sum
 * is the equation above. A difference would mean the trial balance itself is broken, which
 * is why the report states the check rather than assuming it.
 *
 * ## Current Earnings, and why it is computed rather than posted
 *
 * Profit belongs to the owners, so it must appear under equity or the sheet cannot balance.
 * Conventionally a year-end closing entry moves it into retained earnings — but **year-end
 * closing is not implemented**, and this service will not invent it. `3200 Retained
 * Earnings` shows its actual general-ledger balance and is never written to from here.
 *
 * Instead the unclosed profit is shown as a computed line, **Current Earnings**.
 *
 * ## The accumulation boundary
 *
 * Current Earnings is the cumulative profit from the beginning of recorded history to the
 * report date — not the current calendar year, which this system has no basis to assume:
 * fiscal periods are individual months with no year grouping, and nothing records where a
 * financial year begins.
 *
 * While no closing entry has ever been posted, "since inception" is not an approximation —
 * it is the correct figure, because every rupee of profit ever earned is still unclosed.
 *
 * **The limitation, stated plainly:** once year-end closing exists, this must start from the
 * last close instead, or profit already moved into retained earnings would be counted twice.
 * See docs/ACCOUNTING_RULES.md §24.
 */
class BalanceSheetService
{
    public function __construct(
        private readonly AccountingBalanceService $balances,
        private readonly AccountTreeService $tree,
        private readonly ProfitLossService $profitLoss,
    ) {}

    /**
     * @return array{
     *     assets: array{rows: list<array<string, mixed>>, total: string},
     *     liabilities: array{rows: list<array<string, mixed>>, total: string},
     *     equity: array{rows: list<array<string, mixed>>, total: string},
     *     current_earnings: string,
     *     total_equity: string,
     *     liabilities_and_equity: string,
     *     difference: string,
     *     balanced: bool,
     *     as_of: string
     * }
     */
    public function statement(?CarbonInterface $asOf = null): array
    {
        $asOfDate = $asOf === null ? null : Carbon::instance($asOf);
        $totals = $this->balances->totalsByAccount($asOfDate);

        $assets = $this->tree->build([AccountType::Asset], $totals);
        $liabilities = $this->tree->build([AccountType::Liability], $totals);
        $equity = $this->tree->build([AccountType::Equity], $totals);

        // Everything earned up to this date and not yet closed. Computed, never posted.
        $currentEarnings = $this->profitLoss->netProfit(null, $asOf);

        $totalEquity = DecimalMath::cost(DecimalMath::add($equity['total'], $currentEarnings));
        $liabilitiesAndEquity = DecimalMath::cost(DecimalMath::add($liabilities['total'], $totalEquity));
        $difference = DecimalMath::cost(DecimalMath::sub($assets['total'], $liabilitiesAndEquity));

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_earnings' => $currentEarnings,
            'total_equity' => $totalEquity,
            'liabilities_and_equity' => $liabilitiesAndEquity,
            'difference' => $difference,
            'balanced' => DecimalMath::isZero($difference),
            'as_of' => ($asOfDate ?? now())->toDateString(),
        ];
    }
}
