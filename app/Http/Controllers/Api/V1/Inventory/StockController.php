<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Inventory\StockPositionResource;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Stock on hand — read only, derived from the inventory ledger by StockQueryService, exactly
 * as the web Stock on Hand screen does. Active products only unless include_inactive=1.
 */
class StockController extends Controller
{
    use PaginatesResults;

    public function __construct(private readonly StockQueryService $stock) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $activeOnly = ! $request->boolean('include_inactive');

        $balances = $this->stock->balancesByProductAndWarehouse(
            $request->integer('product_id') ?: null,
            $request->integer('warehouse_id') ?: null,
        );

        $productIds = $balances->pluck('product_id')->unique()->values()->all();

        $products = Product::query()
            ->with(['primaryUom:id,code', 'secondaryUom:id,code'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $warehouses = Warehouse::query()
            ->whereIn('id', $balances->pluck('warehouse_id')->unique()->all())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        // Company-wide weighted average per product, from the service — never recalculated.
        $costs = $this->stock->costSummaryForProducts($productIds);

        $rows = $balances
            ->filter(fn (object $balance): bool => ($product = $products->get($balance->product_id)) !== null
                && (! $activeOnly || $product->is_active))
            ->map(fn (object $balance): array => [
                'product' => $products->get($balance->product_id),
                'warehouse' => $warehouses->get($balance->warehouse_id),
                'quantity' => $balance->quantity,
                'secondary_quantity' => $balance->secondary_quantity,
                'unit_cost' => $costs[$balance->product_id]['unit_cost'] ?? DecimalMath::zero(DecimalMath::COST_SCALE),
                'value' => $balance->value,
            ])
            ->sortBy([
                fn (array $a, array $b): int => strcmp($a['product']->code, $b['product']->code),
                fn (array $a, array $b): int => strcmp((string) $a['warehouse']?->code, (string) $b['warehouse']?->code),
            ])
            ->values();

        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return StockPositionResource::collection($paginator);
    }
}
