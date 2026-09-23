<?php

namespace App\Http\Controllers\Receivables;

use App\Actions\Receivables\CancelSalesInvoice;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Receivables\ReverseSalesInvoice;
use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The sales invoice lifecycle: post, reverse, cancel.
 *
 * Reversal is an audit correction, not a credit note — see docs/ACCOUNTING_RULES.md §19.
 */
class SalesInvoiceActionController extends Controller
{
    public function post(SalesInvoice $salesInvoice, PostSalesInvoice $action): RedirectResponse
    {
        $action->handle($salesInvoice, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales invoice posted.')]);

        return to_route('sales-invoices.show', $salesInvoice);
    }

    public function reverse(SalesInvoice $salesInvoice, ReverseSalesInvoice $action): RedirectResponse
    {
        $action->handle($salesInvoice, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales invoice reversed.')]);

        return to_route('sales-invoices.show', $salesInvoice);
    }

    public function cancel(SalesInvoice $salesInvoice, CancelSalesInvoice $action): RedirectResponse
    {
        $action->handle($salesInvoice, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft sales invoice cancelled.')]);

        return to_route('sales-invoices.show', $salesInvoice);
    }
}
