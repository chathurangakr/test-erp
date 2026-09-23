<?php

namespace App\Http\Controllers\Receivables;

use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts receivable aging — read only.
 *
 * Derived from posted invoices less the receipts still allocated to them. The Trade
 * Receivables control-account balance is shown beside the total because the two must agree:
 * a difference means something reached the control account that AR does not know about.
 */
class ArAgingController extends Controller
{
    public function __construct(
        private readonly AccountsReceivableService $receivables,
        private readonly AccountingBalanceService $balances,
    ) {}

    public function index(Request $request): Response
    {
        $asOf = $request->date('as_of');
        $report = $this->receivables->aging($asOf === null ? null : Carbon::parse($asOf));

        $control = AccountingSetting::current()?->tradeReceivablesAccount;
        $controlBalance = $control === null
            ? null
            : $this->balances->balance($control, $asOf === null ? null : Carbon::parse($asOf));

        return Inertia::render('finance/ar-aging/Index', [
            'report' => $report,
            'control' => $control === null ? null : [
                'code' => $control->code,
                'name' => $control->name,
                'balance' => $controlBalance,
                'reconciles' => $controlBalance !== null
                    && DecimalMath::compare($controlBalance, $report['totals']['total']) === 0,
            ],
            'filters' => ['as_of' => $asOf?->toDateString()],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
