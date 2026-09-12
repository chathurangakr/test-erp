<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier master.
 *
 * One supplier covers every material the factory buys. Purchases and payments will
 * reference suppliers from their own tables in later phases.
 */
class SupplierController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');

        $suppliers = Supplier::query()
            ->search($search)
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => [
                'search' => $search,
                'status' => $status ?: 'all',
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('suppliers/Create');
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $supplier = Supplier::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier created.')]);

        return to_route('suppliers.show', $supplier);
    }

    public function show(Supplier $supplier): Response
    {
        return Inertia::render('suppliers/Show', [
            'supplier' => $supplier,
        ]);
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('suppliers/Edit', [
            'supplier' => $supplier,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier updated.')]);

        return to_route('suppliers.show', $supplier);
    }
}
