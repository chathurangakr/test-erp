<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\SaveDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\PricingBasis;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreDeliveryRequest;
use App\Http\Requests\Sales\UpdateDeliveryRequest;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductionRecipe;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deliveries (dispatch notes).
 *
 * A draft moves no stock. Posting is a separate, explicit action handled by
 * DeliveryActionController -> PostDelivery.
 *
 * Inventory cost, COGS and gross profit are never accepted from the request: they are
 * produced by the ledger at posting time.
 */
class DeliveryController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly SaveDelivery $saveDelivery,
        private readonly StockQueryService $stock,
    ) {}

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');
        $customerId = $request->integer('customer_id') ?: null;

        $deliveries = Delivery::query()
            ->with(['customer:id,code,name', 'warehouse:id,code', 'lines', 'salesOrder:id,so_number'])
            ->search($search)
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Delivery $delivery): array => $this->summarise($delivery));

        return Inertia::render('sales/deliveries/Index', [
            'deliveries' => $deliveries,
            'filters' => [
                'search' => $search,
                'status' => $status ?: '',
                'customer_id' => $customerId,
            ],
            'customers' => Customer::query()->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => DeliveryStatus::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('sales/deliveries/Create', $this->formOptions());
    }

    public function store(StoreDeliveryRequest $request): RedirectResponse
    {
        $delivery = $this->saveDelivery->handle(null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery saved as draft.')]);

        return to_route('deliveries.show', $delivery);
    }

    public function show(Delivery $delivery): Response
    {
        $delivery->load([
            'customer:id,code,name',
            'warehouse:id,code,name',
            'salesOrder:id,so_number',
            'lines.product:id,code,name,primary_uom_id,secondary_uom_id',
            'lines.product.primaryUom:id,code',
            'lines.product.secondaryUom:id,code',
            'lines.lot:id,lot_number',
            'lines.backflushProduct:id,code,name,primary_uom_id',
            'lines.backflushProduct.primaryUom:id,code',
            'inventoryTransaction:id,reference',
            'backflushTransaction:id,reference',
            'backflushReversalTransaction:id,reference',
            'reversalInventoryTransaction:id,reference',
            'postedBy:id,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('sales/deliveries/Show', [
            'delivery' => $this->detail($delivery),
        ]);
    }

    public function edit(Delivery $delivery): Response
    {
        $this->saveDelivery->assertEditable($delivery);

        $delivery->load('lines');

        return Inertia::render('sales/deliveries/Edit', array_merge($this->formOptions(), [
            'delivery' => [
                'id' => $delivery->id,
                'delivery_number' => $delivery->delivery_number,
                'customer_id' => $delivery->customer_id,
                'sales_order_id' => $delivery->sales_order_id,
                'warehouse_id' => $delivery->warehouse_id,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'customer_reference' => $delivery->customer_reference,
                'vehicle_number' => $delivery->vehicle_number,
                'driver_name' => $delivery->driver_name,
                'notes' => $delivery->notes,
                'lines' => $delivery->lines->map(fn ($line): array => [
                    'product_id' => $line->product_id,
                    'sales_order_line_id' => $line->sales_order_line_id,
                    'lot_id' => $line->lot_id,
                    'quantity' => $line->quantity,
                    'secondary_quantity' => $line->secondary_quantity,
                    'pricing_basis' => $line->pricing_basis->value,
                    'unit_price' => $line->unit_price,
                    'backflush_quantity' => $line->backflush_quantity,
                ])->values(),
            ],
        ]));
    }

    public function update(UpdateDeliveryRequest $request, Delivery $delivery): RedirectResponse
    {
        $this->saveDelivery->handle($delivery, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery updated.')]);

        return to_route('deliveries.show', $delivery);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        // Lots that actually hold stock, so the picker only offers issuable sources.
        $lotBalances = $this->stock->balancesByLot()
            ->filter(fn (object $row): bool => DecimalMath::isPositive($row->quantity));

        // Pooled products have no lots to choose from (docs/INVENTORY_RULES.md §15).
        $lots = InventoryLot::query()
            ->whereHas('product', fn ($query) => $query->where('tracks_lots', true))
            ->whereIn('id', $lotBalances->pluck('lot_id')->unique()->all())
            ->get(['id', 'lot_number', 'product_id'])
            ->map(function (InventoryLot $lot) use ($lotBalances): array {
                $rows = $lotBalances->where('lot_id', $lot->id);

                return [
                    'id' => $lot->id,
                    'lot_number' => $lot->lot_number,
                    'product_id' => $lot->product_id,
                    'warehouses' => $rows->map(fn (object $row): array => [
                        'warehouse_id' => $row->warehouse_id,
                        'quantity' => $row->quantity,
                    ])->values(),
                ];
            })
            ->values();

        // Products whose delivery backflushes a raw material, and which one.
        $backflush = ProductionRecipe::backflushInputsByOutput();

        return [
            'customers' => Customer::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::query()
                ->with(['primaryUom:id,code', 'secondaryUom:id,code'])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'primary_uom_id', 'secondary_uom_id', 'tracks_lots', 'is_active'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'uom' => $product->primaryUom?->code,
                    'secondary_uom' => $product->secondaryUom?->code,
                    'tracks_lots' => $product->tracksLots(),
                    'backflush' => $backflush->has($product->id) ? [
                        'code' => $backflush->get($product->id)->code,
                        'name' => $backflush->get($product->id)->name,
                        'uom' => $backflush->get($product->id)->primaryUom?->code,
                    ] : null,
                    'is_active' => $product->is_active,
                ]),
            'lots' => $lots,
            'pricingBases' => PricingBasis::options(),
            // Only open orders can be delivered against.
            'salesOrders' => SalesOrder::query()
                ->whereIn('status', [SalesOrderStatus::Approved, SalesOrderStatus::PartiallyDelivered])
                ->with(['lines.product:id,code,name,primary_uom_id', 'lines.product.primaryUom:id,code'])
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (SalesOrder $order): array => [
                    'id' => $order->id,
                    'so_number' => $order->so_number,
                    'customer_id' => $order->customer_id,
                    'warehouse_id' => $order->warehouse_id,
                    'lines' => $order->lines
                        ->filter(fn ($line): bool => ! $line->isFullyDelivered())
                        ->map(fn ($line): array => [
                            'id' => $line->id,
                            'product_id' => $line->product_id,
                            'product_code' => $line->product?->code,
                            'uom' => $line->product?->primaryUom?->code,
                            'pricing_basis' => $line->pricing_basis->value,
                            'unit_price' => $line->unit_price,
                            'outstanding_quantity' => $line->outstandingQuantity(),
                        ])->values(),
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Delivery $delivery): array
    {
        $quantity = DecimalMath::zero();

        foreach ($delivery->lines as $line) {
            $quantity = DecimalMath::add($quantity, DecimalMath::of($line->quantity));
        }

        return [
            'id' => $delivery->id,
            'delivery_number' => $delivery->delivery_number,
            'customer_code' => $delivery->customer?->code,
            'customer_name' => $delivery->customer?->name,
            'warehouse_code' => $delivery->warehouse?->code,
            'so_number' => $delivery->salesOrder?->so_number,
            'delivery_date' => $delivery->delivery_date?->toDateString(),
            'status' => $delivery->status->value,
            'status_label' => $delivery->status->label(),
            'total_quantity' => DecimalMath::quantity($quantity),
            'sales_total' => $delivery->salesTotal(),
            // Cost figures exist only once stock has moved.
            'cogs_total' => $delivery->status->reachedInventory() ? $delivery->costOfGoodsSold() : null,
            'gross_profit' => $delivery->status->reachedInventory() ? $delivery->grossProfit() : null,
            'gross_margin' => $delivery->status->reachedInventory() ? $delivery->grossMarginPercent() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Delivery $delivery): array
    {
        return array_merge($this->summarise($delivery), [
            'customer_reference' => $delivery->customer_reference,
            'vehicle_number' => $delivery->vehicle_number,
            'driver_name' => $delivery->driver_name,
            'notes' => $delivery->notes,
            'posted_by' => $delivery->postedBy?->name,
            'posted_at' => BusinessClock::display($delivery->posted_at),
            'created_by' => $delivery->createdBy?->name,
            'reversed_at' => BusinessClock::display($delivery->reversed_at),
            'inventory_reference' => $delivery->inventoryTransaction?->reference,
            'reversal_reference' => $delivery->reversalInventoryTransaction?->reference,
            'backflush_reference' => $delivery->backflushTransaction?->reference,
            'backflush_reversal_reference' => $delivery->backflushReversalTransaction?->reference,
            'can_post' => $delivery->status->canPost(),
            'can_reverse' => $delivery->status->canReverse() && ! $delivery->isReversed(),
            'can_edit' => $delivery->status->isEditable(),
            'can_cancel' => $delivery->status->canCancel(),
            'lines' => $delivery->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_code' => $line->product?->code,
                'product_name' => $line->product?->name,
                'uom' => $line->product?->primaryUom?->code,
                'secondary_uom' => $line->product?->secondaryUom?->code,
                'lot_number' => $line->lot?->lot_number,
                'quantity' => $line->quantity,
                'secondary_quantity' => $line->secondary_quantity,
                'pricing_basis' => $line->pricing_basis->value,
                'unit_price' => $line->unit_price,
                'sales_value' => $line->sales_value,
                'inventory_unit_cost' => $line->inventory_unit_cost,
                'cost_of_goods_sold' => $line->cost_of_goods_sold,
                'backflush_quantity' => $line->backflush_quantity,
                'backflush_cost' => $line->backflush_cost,
                'backflush_code' => $line->backflushProduct?->code,
                'backflush_uom' => $line->backflushProduct?->primaryUom?->code,
                'gross_profit' => $line->grossProfit(),
                'gross_margin' => $line->grossMarginPercent(),
            ])->values(),
        ]);
    }
}
