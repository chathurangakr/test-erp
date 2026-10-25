<?php

namespace App\Http\Controllers\Api\V1\Production\Concerns;

use App\Enums\ProductionType;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Resources\V1\Production\ProductionOrderResource;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What the three production API controllers share: listing one type, loading a run for its
 * resource, and refusing a run of the wrong type.
 *
 * Each controller keeps its own action methods only because route-model binding matches the
 * route parameter by name ({dehusking}, {huskChipping}, {copra}). No production rule lives
 * here — every write goes through the same Save* and Post* actions the website uses.
 */
trait HandlesProductionOrders
{
    use PaginatesResults;

    abstract protected function type(): ProductionType;

    protected function listOrders(Request $request): AnonymousResourceCollection
    {
        $orders = ProductionOrder::query()
            ->where('production_type', $this->type())
            ->with(['warehouse:id,code,name', 'wasteUom:id,code'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('warehouse_id'), fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereOnOrAfter('production_date', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereOnOrBefore('production_date', $d))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ProductionOrderResource::collection($orders);
    }

    /**
     * A run addressed through the wrong type's endpoint does not exist there.
     */
    protected function ensureType(ProductionOrder $order): ProductionOrder
    {
        if ($order->production_type !== $this->type()) {
            throw (new ModelNotFoundException)->setModel(ProductionOrder::class, [$order->getKey()]);
        }

        return $order;
    }

    protected function resource(ProductionOrder $order): ProductionOrderResource
    {
        return new ProductionOrderResource($order->load([
            'warehouse:id,code,name',
            'wasteUom:id,code',
            'inputs.product:id,code,name,primary_uom_id',
            'inputs.product.primaryUom:id,code',
            'inputs.lot:id,lot_number',
            'outputs.product:id,code,name,primary_uom_id,secondary_uom_id',
            'outputs.product.primaryUom:id,code',
            'outputs.product.secondaryUom:id,code',
            'outputs.lot:id,lot_number',
            'consumptionTransaction:id,reference',
            'outputTransaction:id,reference',
            'createdBy:id,name',
            'postedBy:id,name',
            'reversedBy:id,name',
        ]));
    }

    protected function created(ProductionOrder $order): JsonResponse
    {
        return $this->resource($order)->response()->setStatusCode(201);
    }
}
