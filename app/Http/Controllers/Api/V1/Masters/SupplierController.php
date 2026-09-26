<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Http\Resources\V1\Masters\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Suppliers over the API — the same validation as the website. No delete: a supplier is
 * deactivated, never removed, because historic documents refer to it.
 */
class SupplierController extends Controller
{
    use PaginatesResults;

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        $suppliers = Supplier::query()
            ->search((string) $request->string('search'))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('code')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return SupplierResource::collection($suppliers);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        return (new SupplierResource($supplier->refresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier->refresh());
    }

    /**
     * Sets the active flag to the state requested. Unlike the website's toggle, the API takes
     * the target state explicitly, so a retried request cannot flip it back.
     */
    public function status(Request $request, Supplier $supplier): SupplierResource
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $supplier->update(['is_active' => (bool) $validated['is_active']]);

        return new SupplierResource($supplier->refresh());
    }
}
