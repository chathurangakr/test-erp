<?php

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\SaveGoodsReceipt;
use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreGoodsReceiptRequest;
use App\Http\Requests\Purchasing\UpdateGoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Purchasing\LandedCostAllocator;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods receipts.
 *
 * A draft creates no inventory. Posting is a separate, explicit action handled by
 * GoodsReceiptActionController -> PostGoodsReceipt.
 *
 * Landed cost figures are never accepted from the request: they are calculated here for
 * display and recalculated authoritatively at posting time.
 */
class GoodsReceiptController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly SaveGoodsReceipt $saveReceipt,
        private readonly LandedCostAllocator $allocator,
    ) {}

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');
        $supplierId = $request->integer('supplier_id') ?: null;

        $receipts = GoodsReceipt::query()
            ->with(['supplier:id,code,name', 'warehouse:id,code', 'lines', 'purchaseOrder:id,po_number'])
            ->search($search)
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($supplierId, fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (GoodsReceipt $receipt): array => $this->summarise($receipt));

        return Inertia::render('purchasing/goods-receipts/Index', [
            'receipts' => $receipts,
            'filters' => [
                'search' => $search,
                'status' => $status ?: '',
                'supplier_id' => $supplierId,
            ],
            'suppliers' => Supplier::query()->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => GoodsReceiptStatus::options(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('purchasing/goods-receipts/Create', $this->formOptions());
    }

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        $receipt = $this->saveReceipt->handle(null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goods receipt saved as draft.')]);

        return to_route('goods-receipts.show', $receipt);
    }

    public function show(GoodsReceipt $goodsReceipt): Response
    {
        $goodsReceipt->load([
            'supplier:id,code,name',
            'warehouse:id,code,name',
            'purchaseOrder:id,po_number',
            'lines.product:id,code,name,primary_uom_id,secondary_uom_id',
            'lines.product.primaryUom:id,code',
            'lines.product.secondaryUom:id,code',
            'lines.lot:id,lot_number',
            'inventoryTransaction:id,reference',
            'reversalInventoryTransaction:id,reference',
            'postedBy:id,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('purchasing/goods-receipts/Show', [
            'receipt' => $this->detail($goodsReceipt),
        ]);
    }

    public function edit(GoodsReceipt $goodsReceipt): Response
    {
        $this->saveReceipt->assertEditable($goodsReceipt);

        $goodsReceipt->load('lines');

        return Inertia::render('purchasing/goods-receipts/Edit', array_merge($this->formOptions(), [
            'receipt' => [
                'id' => $goodsReceipt->id,
                'grn_number' => $goodsReceipt->grn_number,
                'supplier_id' => $goodsReceipt->supplier_id,
                'purchase_order_id' => $goodsReceipt->purchase_order_id,
                'warehouse_id' => $goodsReceipt->warehouse_id,
                'receipt_date' => $goodsReceipt->receipt_date?->toDateString(),
                'supplier_delivery_reference' => $goodsReceipt->supplier_delivery_reference,
                'transport_cost' => $goodsReceipt->transport_cost,
                'loading_cost' => $goodsReceipt->loading_cost,
                'other_cost' => $goodsReceipt->other_cost,
                'notes' => $goodsReceipt->notes,
                'lines' => $goodsReceipt->lines->map(fn ($line): array => [
                    'product_id' => $line->product_id,
                    'purchase_order_line_id' => $line->purchase_order_line_id,
                    'quantity' => $line->quantity,
                    'secondary_quantity' => $line->secondary_quantity,
                    'unit_price' => $line->unit_price,
                    'supplier_lot_number' => $line->supplier_lot_number,
                ])->values(),
            ],
        ]));
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->saveReceipt->handle($goodsReceipt, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goods receipt updated.')]);

        return to_route('goods-receipts.show', $goodsReceipt);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->operational()->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::query()
                ->where('is_active', true)
                ->with(['primaryUom:id,code', 'secondaryUom:id,code'])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'primary_uom_id', 'secondary_uom_id', 'tracks_lots'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'uom' => $product->primaryUom?->code,
                    'secondary_uom' => $product->secondaryUom?->code,
                    // Pooled products get no lot, so their screen asks for no lot details.
                    'tracks_lots' => $product->tracksLots(),
                ]),
            // Only open orders can be received against.
            'purchaseOrders' => PurchaseOrder::query()
                ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived])
                ->with(['lines.product:id,code,name,primary_uom_id', 'lines.product.primaryUom:id,code'])
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (PurchaseOrder $order): array => [
                    'id' => $order->id,
                    'po_number' => $order->po_number,
                    'supplier_id' => $order->supplier_id,
                    'warehouse_id' => $order->warehouse_id,
                    'lines' => $order->lines
                        ->filter(fn ($line): bool => ! $line->isFullyReceived())
                        ->map(fn ($line): array => [
                            'id' => $line->id,
                            'product_id' => $line->product_id,
                            'product_code' => $line->product?->code,
                            'uom' => $line->product?->primaryUom?->code,
                            'unit_price' => $line->unit_price,
                            'outstanding_quantity' => $line->outstandingQuantity(),
                        ])->values(),
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(GoodsReceipt $receipt): array
    {
        $material = DecimalMath::zero();
        $quantity = DecimalMath::zero();

        foreach ($receipt->lines as $line) {
            $material = DecimalMath::add(
                $material,
                DecimalMath::money(DecimalMath::mul(DecimalMath::of($line->quantity), DecimalMath::of($line->unit_price)))
            );
            $quantity = DecimalMath::add($quantity, DecimalMath::of($line->quantity));
        }

        $material = DecimalMath::cost($material);
        $landed = $receipt->landedCostTotal();

        return [
            'id' => $receipt->id,
            'grn_number' => $receipt->grn_number,
            'supplier_code' => $receipt->supplier?->code,
            'supplier_name' => $receipt->supplier?->name,
            'warehouse_code' => $receipt->warehouse?->code,
            'po_number' => $receipt->purchaseOrder?->po_number,
            'receipt_date' => $receipt->receipt_date?->toDateString(),
            'status' => $receipt->status->value,
            'status_label' => $receipt->status->label(),
            'total_quantity' => DecimalMath::quantity($quantity),
            'material_total' => $material,
            'landed_total' => $landed,
            'grand_total' => DecimalMath::cost(DecimalMath::add($material, $landed)),
            'is_reversed' => $receipt->isReversed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(GoodsReceipt $receipt): array
    {
        $isDraft = $receipt->status === GoodsReceiptStatus::Draft;

        // For a draft, show a live preview of what posting would cost. For a posted
        // receipt, show the figures actually stored at posting time.
        $preview = null;

        if ($isDraft && $receipt->lines->isNotEmpty()) {
            try {
                $preview = $this->allocator->allocate(
                    $receipt->lines->map(fn ($line): array => [
                        'quantity' => DecimalMath::of($line->quantity),
                        'unit_price' => DecimalMath::of($line->unit_price),
                    ])->all(),
                    $receipt->landedCostTotal(),
                );
            } catch (ValidationException) {
                // A preview that cannot be calculated (e.g. zero material value with landed
                // costs) is simply not shown; posting will report the error properly.
                $preview = null;
            }
        }

        return array_merge($this->summarise($receipt), [
            'supplier_delivery_reference' => $receipt->supplier_delivery_reference,
            'transport_cost' => $receipt->transport_cost,
            'loading_cost' => $receipt->loading_cost,
            'other_cost' => $receipt->other_cost,
            'notes' => $receipt->notes,
            'posted_by' => $receipt->postedBy?->name,
            'posted_at' => BusinessClock::display($receipt->posted_at),
            'created_by' => $receipt->createdBy?->name,
            'reversed_at' => BusinessClock::display($receipt->reversed_at),
            'inventory_reference' => $receipt->inventoryTransaction?->reference,
            'reversal_reference' => $receipt->reversalInventoryTransaction?->reference,
            'can_post' => $receipt->status->canPost(),
            'can_reverse' => $receipt->status->canReverse() && ! $receipt->isReversed(),
            'can_edit' => $receipt->status->isEditable(),
            'can_cancel' => $receipt->status->canCancel(),
            'lines' => $receipt->lines->values()->map(function ($line, $index) use ($preview, $isDraft): array {
                $costs = $preview['lines'][$index] ?? null;

                return [
                    'id' => $line->id,
                    'product_code' => $line->product?->code,
                    'product_name' => $line->product?->name,
                    'uom' => $line->product?->primaryUom?->code,
                    'secondary_uom' => $line->product?->secondaryUom?->code,
                    'quantity' => $line->quantity,
                    'secondary_quantity' => $line->secondary_quantity,
                    'unit_price' => $line->unit_price,
                    'material_cost' => $isDraft
                        ? ($costs['material_cost'] ?? null)
                        : $line->material_cost,
                    'allocated_landed_cost' => $isDraft
                        ? ($costs['allocated_landed_cost'] ?? null)
                        : $line->allocated_landed_cost,
                    'final_unit_cost' => $isDraft
                        ? ($costs['final_unit_cost'] ?? null)
                        : $line->final_unit_cost,
                    'lot_number' => $line->lot?->lot_number,
                    'supplier_lot_number' => $line->supplier_lot_number,
                ];
            })->values(),
        ]);
    }
}
