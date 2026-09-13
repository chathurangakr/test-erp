<?php

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\ApprovePurchaseOrder;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Approving and cancelling purchase orders.
 *
 * The lifecycle rules live in the action classes, not here.
 */
class PurchaseOrderApprovalController extends Controller
{
    public function approve(PurchaseOrder $order, ApprovePurchaseOrder $action): RedirectResponse
    {
        $action->handle($order);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase order approved.')]);

        return back();
    }

    public function cancel(PurchaseOrder $order, CancelPurchaseOrder $action): RedirectResponse
    {
        $action->handle($order);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase order cancelled.')]);

        return back();
    }
}
