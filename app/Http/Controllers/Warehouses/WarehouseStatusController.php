<?php

namespace App\Http\Controllers\Warehouses;

use App\Actions\Warehouses\ToggleWarehouseStatus;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Activates and deactivates a warehouse.
 *
 * Deactivation replaces deletion. Refusing to deactivate the default warehouse is handled
 * by the action, which throws a validation error rather than silently reassigning.
 */
class WarehouseStatusController extends Controller
{
    public function update(Warehouse $warehouse, ToggleWarehouseStatus $toggle): RedirectResponse
    {
        $toggle->handle($warehouse);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $warehouse->is_active ? __('Warehouse activated.') : __('Warehouse deactivated.'),
        ]);

        return back();
    }
}
