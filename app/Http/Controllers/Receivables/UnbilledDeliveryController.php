<?php

namespace App\Http\Controllers\Receivables;

use App\Http\Controllers\Controller;
use App\Services\Accounting\SalesBillingReconciliationService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deliveries that have not yet been billed — read only.
 *
 * The gap between what has left the warehouse and what has been invoiced. A delivery records
 * cost of sales when the goods go out; the revenue waits for the invoice, so a balance here
 * is work done but not yet billed rather than an error.
 */
class UnbilledDeliveryController extends Controller
{
    public function __construct(private readonly SalesBillingReconciliationService $billing) {}

    public function index(): Response
    {
        return Inertia::render('finance/unbilled-deliveries/Index', [
            'report' => $this->billing->report(),
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
