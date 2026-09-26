<?php

namespace App\Http\Controllers\Payables;

use App\Http\Controllers\Controller;
use App\Services\Accounting\ControlAccountReconciliation;
use App\Services\Accounting\GrniReconciliationService;
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
        private readonly ControlAccountReconciliation $control,
    ) {}

    public function index(): Response
    {
        $report = $this->grni->report();

        return Inertia::render('finance/grni-reconciliation/Index', [
            'report' => $report,
            'control' => $this->control->grni($report['remaining_total']),
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
