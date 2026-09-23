<?php

namespace App\Http\Controllers\Payables;

use App\Actions\Payables\CancelSupplierInvoice;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Payables\ReverseSupplierInvoice;
use App\Http\Controllers\Controller;
use App\Models\SupplierInvoice;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The supplier invoice lifecycle: post, reverse, cancel.
 *
 * Business events, not form saves — each delegates to an action so a future REST client
 * reuses exactly the same rules.
 */
class SupplierInvoiceActionController extends Controller
{
    public function post(SupplierInvoice $supplierInvoice, PostSupplierInvoice $action): RedirectResponse
    {
        $action->handle($supplierInvoice, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier invoice posted.')]);

        return to_route('supplier-invoices.show', $supplierInvoice);
    }

    public function reverse(SupplierInvoice $supplierInvoice, ReverseSupplierInvoice $action): RedirectResponse
    {
        $action->handle($supplierInvoice, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier invoice reversed.')]);

        return to_route('supplier-invoices.show', $supplierInvoice);
    }

    public function cancel(SupplierInvoice $supplierInvoice, CancelSupplierInvoice $action): RedirectResponse
    {
        $action->handle($supplierInvoice, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft supplier invoice cancelled.')]);

        return to_route('supplier-invoices.show', $supplierInvoice);
    }
}
