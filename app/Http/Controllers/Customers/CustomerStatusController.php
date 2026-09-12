<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Activates and deactivates a customer.
 *
 * Deactivation replaces deletion: master data referenced by historic transactions must
 * remain in the system (see CLAUDE.md — audit history is never deleted).
 */
class CustomerStatusController extends Controller
{
    public function update(Customer $customer): RedirectResponse
    {
        $customer->update(['is_active' => ! $customer->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $customer->is_active ? __('Customer activated.') : __('Customer deactivated.'),
        ]);

        return back();
    }
}
