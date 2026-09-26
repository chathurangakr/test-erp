<?php

namespace App\Http\Controllers\Payables;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\ControlAccountReconciliation;
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
        private readonly ControlAccountReconciliation $control,
    ) {}

    public function index(Request $request): Response
    {
        $asOf = $request->date('as_of');
        $report = $this->payables->aging($asOf === null ? null : Carbon::parse($asOf));

        return Inertia::render('finance/ap-aging/Index', [
            'report' => $report,
            'control' => $this->control->tradePayables($report['totals']['total'], $asOf === null ? null : Carbon::parse($asOf)),
            'filters' => ['as_of' => $asOf?->toDateString()],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
