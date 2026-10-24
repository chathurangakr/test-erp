<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
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
    ) {}

    public function index(Request $request): Response
    {
        $asOf = $request->date('as_of');
        $asOfDate = $asOf === null ? null : Carbon::parse($asOf);

        return Inertia::render('finance/reports/BalanceSheet', [
            'statement' => $this->balanceSheet->statement($asOfDate),
            // The profit behind current earnings, so the reader can see where it came from.
            'earnings' => $this->profitLoss->statement(null, $asOfDate, sinceLastClose: true),
            'retainedEarnings' => $this->balanceSheet->retainedEarnings($asOfDate),
            'filters' => ['as_of' => $asOfDate?->toDateString()],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
