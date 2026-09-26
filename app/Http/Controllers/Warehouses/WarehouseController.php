<?php

namespace App\Http\Controllers\Warehouses;

use App\Actions\Warehouses\SaveWarehouse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouses\StoreWarehouseRequest;
use App\Http\Requests\Warehouses\UpdateWarehouseRequest;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Warehouse master.
 *
 * Inventory movements will require a warehouse, so the ERP is multi-warehouse from the
 * start. Exactly one active warehouse is the default; that invariant is maintained by
 * SetDefaultWarehouse rather than by direct assignment.
 */
class WarehouseController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');

        $warehouses = Warehouse::query()
            ->search($search)
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('warehouses/Index', [
            'warehouses' => $warehouses,
            'filters' => [
                'search' => $search,
                'status' => $status ?: 'all',
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('warehouses/Create');
    }

    public function store(StoreWarehouseRequest $request, SaveWarehouse $saveWarehouse): RedirectResponse
    {
        $warehouse = $saveWarehouse->handle(null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Warehouse created.')]);

        return to_route('warehouses.show', $warehouse);
    }

    public function show(Warehouse $warehouse): Response
    {
        return Inertia::render('warehouses/Show', [
            'warehouse' => $warehouse,
        ]);
    }

    public function edit(Warehouse $warehouse): Response
    {
        return Inertia::render('warehouses/Edit', [
            'warehouse' => $warehouse,
        ]);
    }

    public function update(
        UpdateWarehouseRequest $request,
        Warehouse $warehouse,
        SaveWarehouse $saveWarehouse,
    ): RedirectResponse {
        $saveWarehouse->handle($warehouse, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Warehouse updated.')]);

        return to_route('warehouses.show', $warehouse);
    }
}
