<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product master.
 *
 * Products are never hard-deleted through the UI — they are deactivated instead, so that
 * historic transactions referencing them remain intelligible.
 */
class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');

        $list = ListQuery::from($request)->sortable([
            'code' => 'code',
            'name' => 'name',
            'primary_uom' => fn (Builder $q, string $d) => $q->orderBy(UnitOfMeasure::select('code')->whereColumn('units_of_measure.id', 'products.primary_uom_id'), $d),
            'secondary_uom' => fn (Builder $q, string $d) => $q->orderBy(UnitOfMeasure::select('code')->whereColumn('units_of_measure.id', 'products.secondary_uom_id'), $d),
            'status' => 'is_active',
        ], 'code', 'asc');

        $products = $list->paginate(Product::query()
            ->with(['primaryUom:id,code', 'secondaryUom:id,code'])
            ->search($search)
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false)));

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $search,
                'status' => $status ?: 'all',
            ] + $list->filters(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/Create', [
            'unitsOfMeasure' => $this->activeUnits(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('products.show', $product);
    }

    public function show(Product $product): Response
    {
        $product->load(['primaryUom:id,code,name', 'secondaryUom:id,code,name']);

        return Inertia::render('products/Show', [
            'product' => $product,
        ]);
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/Edit', [
            'product' => $product,
            'unitsOfMeasure' => $this->activeUnits(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return to_route('products.show', $product);
    }

    /**
     * Units offered in the form. Inactive units are excluded so they cannot be newly
     * assigned, but existing assignments are unaffected.
     *
     * @return Collection<int, UnitOfMeasure>
     */
    private function activeUnits()
    {
        return UnitOfMeasure::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'decimal_places']);
    }
}
