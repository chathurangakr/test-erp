<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use App\Support\ListQuery;
use App\Support\SourceDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only lot listing.
 *
 * Lots are created by the posting services (and, later, by purchase and production
 * documents), not by hand — so there is no manual creation screen.
 */
class InventoryLotController extends Controller
{
    public function __construct(private readonly StockQueryService $stock) {}

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $productId = $request->integer('product_id') ?: null;
        $status = (string) $request->string('status');

        $list = ListQuery::from($request)->period('received_at')->sortable([
            'lot' => 'lot_number',
            'product' => fn (Builder $q, string $d) => $q->orderBy(Product::select('code')->whereColumn('products.id', 'inventory_lots.product_id'), $d),
            'manufactured' => 'manufactured_at',
            'received' => 'received_at',
            'status' => 'is_active',
        ], 'received');

        $lots = $list->paginate(InventoryLot::query()
            ->with(['product:id,code,name,primary_uom_id', 'product.primaryUom:id,code'])
            ->search($search)
            ->when($productId, fn (Builder $q, int $id) => $q->where('product_id', $id))
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false)));

        $balances = $this->stock->balancesByLot()->groupBy('lot_id');

        $warehouses = Warehouse::query()->get(['id', 'code'])->keyBy('id');

        $lots->through(function (InventoryLot $lot) use ($balances, $warehouses): array {
            $lotBalances = $balances->get($lot->id, collect());

            $total = $lotBalances->reduce(
                fn (string $carry, object $row): string => DecimalMath::add($carry, $row->quantity),
                DecimalMath::zero(),
            );

            return [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'supplier_lot_number' => $lot->supplier_lot_number,
                'product_code' => $lot->product?->code,
                'product_name' => $lot->product?->name,
                'primary_uom' => $lot->product?->primaryUom?->code,
                'source_type' => $lot->source_type,
                'source' => $lot->source_type === null ? null : SourceDocument::label($lot->source_type),
                'manufactured_at' => $lot->manufactured_at?->toDateString(),
                'received_at' => $lot->received_at?->toDateString(),
                'quantity' => DecimalMath::quantity($total),
                'warehouses' => $lotBalances
                    ->filter(fn (object $row): bool => ! DecimalMath::isZero($row->quantity))
                    ->map(fn (object $row): array => [
                        'code' => $warehouses->get($row->warehouse_id)?->code,
                        'quantity' => $row->quantity,
                    ])
                    ->values(),
                'is_active' => $lot->is_active,
            ];
        });

        return Inertia::render('inventory/Lots', [
            'lots' => $lots,
            'filters' => [
                'search' => $search,
                'product_id' => $productId,
                'status' => $status ?: 'all',
            ] + $list->filters(),
            'products' => Product::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }
}
