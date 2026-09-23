<?php

namespace App\Http\Controllers\Payables;

use App\Actions\Payables\CancelSupplierPayment;
use App\Actions\Payables\PostSupplierPayment;
use App\Actions\Payables\ReverseSupplierPayment;
use App\Http\Controllers\Controller;
use App\Models\SupplierPayment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The supplier payment lifecycle: post, reverse, cancel.
 */
class SupplierPaymentActionController extends Controller
{
    public function post(SupplierPayment $supplierPayment, PostSupplierPayment $action): RedirectResponse
    {
        $action->handle($supplierPayment, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier payment posted.')]);

        return to_route('supplier-payments.show', $supplierPayment);
    }

    public function reverse(SupplierPayment $supplierPayment, ReverseSupplierPayment $action): RedirectResponse
    {
        $action->handle($supplierPayment, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier payment reversed.')]);

        return to_route('supplier-payments.show', $supplierPayment);
    }

    public function cancel(SupplierPayment $supplierPayment, CancelSupplierPayment $action): RedirectResponse
    {
        $action->handle($supplierPayment, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft supplier payment cancelled.')]);

        return to_route('supplier-payments.show', $supplierPayment);
    }
}
