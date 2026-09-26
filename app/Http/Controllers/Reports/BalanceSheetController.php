<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\ProfitLossService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The balance sheet — read only.
 *
 * Retained earnings is shown alongside current earnings deliberately: the first is what the
 * ledger actually holds, the second is computed profit that no closing entry has moved yet.
 * Conflating them is the mistake this screen exists to prevent.
 */
class BalanceSheetController extends Controller
{
    public function __construct(
        private readonly BalanceSheetService $balanceSheet,
        private readonly ProfitLossService $profitLoss,
        private readonly AccountingBalanceService $balances,
    ) {}

    public function index(Request $request): Response
    {
        $asOf = $request->date('as_of');
        $asOfDate = $asOf === null ? null : Carbon::parse($asOf);

        $retained = Account::query()
            ->where('code', (string) config('accounting.retained_earnings_code', '3200'))
            ->first();

        return Inertia::render('finance/reports/BalanceSheet', [
            'statement' => $this->balanceSheet->statement($asOfDate),
            // The profit behind current earnings, so the reader can see where it came from.
            'earnings' => $this->profitLoss->statement(null, $asOfDate),
            'retainedEarnings' => $retained === null ? null : [
                'account_id' => $retained->id,
                'code' => $retained->code,
                'name' => $retained->name,
                'balance' => $this->balances->balance($retained, $asOfDate),
            ],
            'filters' => ['as_of' => $asOfDate?->toDateString()],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
