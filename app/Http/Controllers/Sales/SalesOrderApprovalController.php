<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\ApproveSalesOrder;
use App\Actions\Sales\CancelSalesOrder;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Approving and cancelling sales orders. Lifecycle rules live in the action classes.
 */
class SalesOrderApprovalController extends Controller
{
    public function approve(SalesOrder $order, ApproveSalesOrder $action): RedirectResponse
    {
        $action->handle($order);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales order approved.')]);

        return back();
    }

    public function cancel(SalesOrder $order, CancelSalesOrder $action): RedirectResponse
    {
        $action->handle($order);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales order cancelled.')]);

        return back();
    }
}
