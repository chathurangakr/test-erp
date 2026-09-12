<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Activates and deactivates a supplier.
 *
 * Deactivation replaces deletion: master data referenced by historic transactions must
 * remain in the system (see CLAUDE.md — audit history is never deleted).
 */
class SupplierStatusController extends Controller
{
    public function update(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['is_active' => ! $supplier->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $supplier->is_active ? __('Supplier activated.') : __('Supplier deactivated.'),
        ]);

        return back();
    }
}
