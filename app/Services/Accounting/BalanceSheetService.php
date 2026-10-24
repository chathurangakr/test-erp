<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\BusinessClock;
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
 * A year-end close (App\Actions\Accounting\CloseFinancialYear) moves a finished year's profit
 * into retained earnings with a closing journal; `3200 Retained Earnings` shows its actual
 * general-ledger balance and is never written to from here.
 *
 * Profit not yet closed is shown as a computed line, **Current Earnings**.
 *
 * ## The accumulation boundary
 *
 * Current Earnings is the profit SINCE THE LAST CLOSE: the profit and loss accounts' balances to
 * the report date, closing journals included. A closing journal empties those accounts as at
 * the year end, so counting it leaves exactly what is still unclosed — never profit already in
 * retained earnings (which would count it twice). With no close yet, it is all profit since
 * the beginning of recorded history. See docs/ACCOUNTING_RULES.md §24.
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
        $currentEarnings = $this->profitLoss->netProfit(null, $asOf, sinceLastClose: true);

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
            'as_of' => $asOfDate?->toDateString() ?? BusinessClock::todayString(),
        ];
    }

    /**
     * The retained earnings account's ACTUAL ledger balance, shown beside the computed
     * Current Earnings so the two are never confused. Nothing posts to it automatically.
     * Located through config('accounting.retained_earnings_code'), never a literal.
     *
     * @return array{account_id: int, code: string, name: string, balance: string}|null
     */
    public function retainedEarnings(?CarbonInterface $asOf = null): ?array
    {
        $account = Account::query()
            ->where('code', (string) config('accounting.retained_earnings_code', '3200'))
            ->first();

        if ($account === null) {
            return null;
        }

        return [
            'account_id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'balance' => $this->balances->balance($account, $asOf === null ? null : Carbon::instance($asOf)),
        ];
    }
}
