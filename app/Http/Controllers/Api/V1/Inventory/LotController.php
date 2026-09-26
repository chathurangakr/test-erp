<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Inventory\LotResource;
use App\Models\InventoryLot;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Inventory lots with their on-hand quantity per warehouse — read only, paginated, with the
 * web Lots screen's filters (search, product, active status). Balances come from
 * StockQueryService::balancesByLot().
 */
class LotController extends Controller
{
    use PaginatesResults;

    public function __construct(private readonly StockQueryService $stock) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        $lots = InventoryLot::query()
            ->with(['product:id,code,name,primary_uom_id', 'product.primaryUom:id,code'])
            ->search((string) $request->string('search'))
            ->when($request->integer('product_id'), fn (Builder $q, int $id) => $q->where('product_id', $id))
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $balances = $this->stock->balancesByLot()->groupBy('lot_id');
        $warehouses = Warehouse::query()->get(['id', 'code', 'name'])->keyBy('id');

        $lots->through(fn (InventoryLot $lot): array => [
            'lot' => $lot,
            'balances' => $balances->get($lot->id, collect()),
            'warehouses' => $warehouses,
        ]);

        return LotResource::collection($lots);
    }
}
