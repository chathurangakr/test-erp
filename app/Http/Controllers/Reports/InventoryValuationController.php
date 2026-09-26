<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Accounting\InventoryValuationReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the inventory is worth, from the inventory ledger — read only.
 *
 * There is no as-of date filter. `StockQueryService` derives balances from the whole ledger,
 * so a historical valuation cannot be produced without a second, divergent definition of
 * stock. Left unbuilt rather than approximated.
 */
class InventoryValuationController extends Controller
{
    public function __construct(private readonly InventoryValuationReportService $valuation) {}

    public function index(Request $request): Response
    {
        $productId = $request->integer('product_id') ?: null;
        $warehouseId = $request->integer('warehouse_id') ?: null;

        return Inertia::render('finance/reports/InventoryValuation', [
            'report' => $this->valuation->report($productId, $warehouseId),
            'products' => Product::query()->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
