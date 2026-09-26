<?php

namespace App\Http\Controllers\Receivables;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\ControlAccountReconciliation;
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
        private readonly ControlAccountReconciliation $control,
    ) {}

    public function index(Request $request): Response
    {
        $asOf = $request->date('as_of');
        $report = $this->receivables->aging($asOf === null ? null : Carbon::parse($asOf));

        return Inertia::render('finance/ar-aging/Index', [
            'report' => $report,
            'control' => $this->control->tradeReceivables($report['totals']['total'], $asOf === null ? null : Carbon::parse($asOf)),
            'filters' => ['as_of' => $asOf?->toDateString()],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
