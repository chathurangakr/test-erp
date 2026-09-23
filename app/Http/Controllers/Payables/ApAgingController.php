<?php

namespace App\Http\Controllers\Payables;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts payable aging — read only.
 *
 * Every figure is derived from posted invoices less the allocations of payments that are
 * still posted. The screen also shows the Trade Payables control-account balance beside the
 * total, because the two must agree: a difference means something has been posted to the
 * control account that accounts payable does not know about.
 */
class ApAgingController extends Controller
{
    public function __construct(
        private readonly AccountsPayableService $payables,
        private readonly AccountingBalanceService $balances,
    ) {}

    public function index(Request $request): Response
    {
        $asOf = $request->date('as_of');
        $report = $this->payables->aging($asOf === null ? null : Carbon::parse($asOf));

        $control = AccountingSetting::current()?->tradePayablesAccount;
        $controlBalance = $control === null
            ? null
            : $this->balances->balance($control, $asOf === null ? null : Carbon::parse($asOf));

        return Inertia::render('finance/ap-aging/Index', [
            'report' => $report,
            'control' => $control === null ? null : [
                'code' => $control->code,
                'name' => $control->name,
                'balance' => $controlBalance,
                // They must agree; the screen says so plainly rather than hiding a difference.
                'reconciles' => $controlBalance !== null
                    && DecimalMath::compare($controlBalance, $report['totals']['total']) === 0,
            ],
            'filters' => ['as_of' => $asOf?->toDateString()],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
