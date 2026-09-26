<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Accounting\InventoryGlReconciliationService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventory ledger against general ledger — read only.
 *
 * Differences are displayed, never corrected. The most common legitimate cause is stock that
 * moved before the accounting cutover and so raised no journal, which the screen explains
 * rather than leaving the reader to guess at.
 */
class InventoryGlReconciliationController extends Controller
{
    public function __construct(private readonly InventoryGlReconciliationService $reconciliation) {}

    public function index(): Response
    {
        return Inertia::render('finance/reports/InventoryGlReconciliation', [
            'report' => $this->reconciliation->report(),
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
