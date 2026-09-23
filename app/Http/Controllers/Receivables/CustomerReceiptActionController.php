<?php

namespace App\Http\Controllers\Receivables;

use App\Actions\Receivables\CancelCustomerReceipt;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Actions\Receivables\ReverseCustomerReceipt;
use App\Http\Controllers\Controller;
use App\Models\CustomerReceipt;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The customer receipt lifecycle: post, reverse, cancel.
 */
class CustomerReceiptActionController extends Controller
{
    public function post(CustomerReceipt $customerReceipt, PostCustomerReceipt $action): RedirectResponse
    {
        $action->handle($customerReceipt, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer receipt posted.')]);

        return to_route('customer-receipts.show', $customerReceipt);
    }

    public function reverse(CustomerReceipt $customerReceipt, ReverseCustomerReceipt $action): RedirectResponse
    {
        $action->handle($customerReceipt, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer receipt reversed.')]);

        return to_route('customer-receipts.show', $customerReceipt);
    }

    public function cancel(CustomerReceipt $customerReceipt, CancelCustomerReceipt $action): RedirectResponse
    {
        $action->handle($customerReceipt, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft customer receipt cancelled.')]);

        return to_route('customer-receipts.show', $customerReceipt);
    }
}
