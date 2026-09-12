<?php

namespace App\Http\Controllers\Warehouses;

use App\Actions\Warehouses\SetDefaultWarehouse;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Makes a warehouse the default warehouse.
 */
class WarehouseDefaultController extends Controller
{
    public function update(Warehouse $warehouse, SetDefaultWarehouse $setDefault): RedirectResponse
    {
        $setDefault->handle($warehouse);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Default warehouse updated.'),
        ]);

        return back();
    }
}
