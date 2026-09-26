<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Actions\Warehouses\SaveWarehouse;
use App\Actions\Warehouses\SetDefaultWarehouse;
use App\Actions\Warehouses\ToggleWarehouseStatus;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouses\StoreWarehouseRequest;
use App\Http\Requests\Warehouses\UpdateWarehouseRequest;
use App\Http\Resources\V1\Masters\WarehouseResource;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Warehouses over the API. Saving and the default-warehouse invariant go through the same
 * actions as the website (SaveWarehouse, SetDefaultWarehouse, ToggleWarehouseStatus).
 */
class WarehouseController extends Controller
{
    use PaginatesResults;

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        $warehouses = Warehouse::query()
            ->search((string) $request->string('search'))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return WarehouseResource::collection($warehouses);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        return new WarehouseResource($warehouse);
    }

    public function store(StoreWarehouseRequest $request, SaveWarehouse $save): JsonResponse
    {
        return (new WarehouseResource($save->handle(null, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse, SaveWarehouse $save): WarehouseResource
    {
        return new WarehouseResource($save->handle($warehouse, $request->validated()));
    }

    /**
     * Sets the active flag to the state requested — idempotent, so a retry cannot flip it
     * back. The default warehouse still cannot be deactivated (ToggleWarehouseStatus).
     */
    public function status(Request $request, Warehouse $warehouse, ToggleWarehouseStatus $toggle): WarehouseResource
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        if ((bool) $validated['is_active'] !== $warehouse->is_active) {
            $toggle->handle($warehouse);
        }

        return new WarehouseResource($warehouse->refresh());
    }

    public function makeDefault(Warehouse $warehouse, SetDefaultWarehouse $setDefault): WarehouseResource
    {
        return new WarehouseResource($setDefault->handle($warehouse));
    }
}
