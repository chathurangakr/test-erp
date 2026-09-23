<?php

namespace App\Http\Controllers\Payables;

use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\GrniReconciliationService;
use App\Support\DecimalMath;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods Received Not Invoiced reconciliation — read only.
 *
 * Every goods receipt that raised an accrual, how much of it supplier invoices have cleared,
 * and what is left. The remaining total must equal the GRNI control-account balance; because
 * GRNI is a control account nothing can post to it by hand, so a difference would mean a
 * genuine problem rather than a stray manual entry.
 */
class GrniReconciliationController extends Controller
{
    public function __construct(
        private readonly GrniReconciliationService $grni,
        private readonly AccountingBalanceService $balances,
    ) {}

    public function index(): Response
    {
        $report = $this->grni->report();

        $control = AccountingSetting::current()?->grniAccount;
        $controlBalance = $control === null ? null : $this->balances->balance($control);

        return Inertia::render('finance/grni-reconciliation/Index', [
            'report' => $report,
            'control' => $control === null ? null : [
                'code' => $control->code,
                'name' => $control->name,
                'balance' => $controlBalance,
                'reconciles' => $controlBalance !== null
                    && DecimalMath::compare($controlBalance, $report['remaining_total']) === 0,
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
