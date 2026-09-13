<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only stock on hand.
 *
 * Every figure is derived from the ledger by StockQueryService — there is no stock column
 * to read and no cache. See docs/INVENTORY_RULES.md §1.
 */
class StockOnHandController extends Controller
{
    public function __construct(private readonly StockQueryService $stock) {}

    public function index(Request $request): Response
    {
        $productId = $request->integer('product_id') ?: null;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        // Active products only by default, as specified.
        $activeOnly = ! $request->boolean('include_inactive');

        $balances = $this->stock->balancesByProductAndWarehouse($productId, $warehouseId);

        $products = Product::query()
            ->with(['primaryUom:id,code', 'secondaryUom:id,code'])
            ->whereIn('id', $balances->pluck('product_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $warehouses = Warehouse::query()
            ->whereIn('id', $balances->pluck('warehouse_id')->unique()->all())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        // Weighted average cost is a company-level figure, so it is looked up per product
        // and repeated across that product's warehouse rows rather than recalculated.
        $costs = $this->stock->costSummaryForProducts(
            $balances->pluck('product_id')->unique()->values()->all()
        );

        $rows = $balances
            ->filter(function (object $balance) use ($products, $activeOnly): bool {
                $product = $products->get($balance->product_id);

                if ($product === null) {
                    return false;
                }

                return ! $activeOnly || $product->is_active;
            })
            ->map(function (object $balance) use ($products, $warehouses, $costs): array {
                $product = $products->get($balance->product_id);
                $warehouse = $warehouses->get($balance->warehouse_id);
                $unitCost = $costs[$balance->product_id]['unit_cost'] ?? DecimalMath::zero(DecimalMath::COST_SCALE);

                return [
                    'product_id' => $balance->product_id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'product_is_active' => $product->is_active,
                    'warehouse_id' => $balance->warehouse_id,
                    'warehouse_code' => $warehouse?->code,
                    'warehouse_name' => $warehouse?->name,
                    'quantity' => $balance->quantity,
                    'primary_uom' => $product->primaryUom?->code,
                    'secondary_quantity' => $product->secondary_uom_id === null
                        ? null
                        : $balance->secondary_quantity,
                    'secondary_uom' => $product->secondaryUom?->code,
                    'unit_cost' => $unitCost,
                    'value' => $balance->value,
                ];
            })
            ->sortBy([['product_code', 'asc'], ['warehouse_code', 'asc']])
            ->values();

        return Inertia::render('inventory/StockOnHand', [
            'rows' => $rows,
            'filters' => [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'include_inactive' => ! $activeOnly,
            ],
            'products' => Product::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }
}
