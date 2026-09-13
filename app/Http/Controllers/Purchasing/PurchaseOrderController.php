<?php

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\GeneratePurchasingReference;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseOrderRequest;
use App\Http\Requests\Purchasing\UpdatePurchaseOrderRequest;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase orders.
 *
 * A purchase order creates no inventory; it records an intention to buy. Only a DRAFT may
 * be edited — approval turns it into a controlled document.
 */
class PurchaseOrderController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly GeneratePurchasingReference $references) {}

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');
        $supplierId = $request->integer('supplier_id') ?: null;

        $orders = PurchaseOrder::query()
            ->with(['supplier:id,code,name', 'warehouse:id,code', 'lines'])
            ->search($search)
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($supplierId, fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (PurchaseOrder $order): array => $this->summarise($order));

        return Inertia::render('purchasing/purchase-orders/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $status ?: '',
                'supplier_id' => $supplierId,
            ],
            'suppliers' => Supplier::query()->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => PurchaseOrderStatus::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('purchasing/purchase-orders/Create', $this->formOptions());
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($data): PurchaseOrder {
            $order = PurchaseOrder::create([
                'po_number' => $this->references->forPurchaseOrder(),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'status' => PurchaseOrderStatus::Draft,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($order, $data['lines']);

            return $order;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase order created.')]);

        return to_route('purchase-orders.show', $order);
    }

    public function show(PurchaseOrder $order): Response
    {
        $order->load([
            'supplier:id,code,name',
            'warehouse:id,code,name',
            'lines.product:id,code,name,primary_uom_id',
            'lines.product.primaryUom:id,code',
            'goodsReceipts:id,grn_number,goods_receipts.purchase_order_id,receipt_date,status',
            'approvedBy:id,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('purchasing/purchase-orders/Show', [
            'order' => $this->detail($order),
        ]);
    }

    public function edit(PurchaseOrder $order): Response
    {
        $this->assertEditable($order);

        $order->load(['lines']);

        return Inertia::render('purchasing/purchase-orders/Edit', array_merge($this->formOptions(), [
            'order' => [
                'id' => $order->id,
                'po_number' => $order->po_number,
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => $order->warehouse_id,
                'order_date' => $order->order_date?->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
                'supplier_reference' => $order->supplier_reference,
                'notes' => $order->notes,
                'lines' => $order->lines->map(fn ($line): array => [
                    'product_id' => $line->product_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                ])->values(),
            ],
        ]));
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $order): RedirectResponse
    {
        $this->assertEditable($order);

        $data = $request->validated();

        DB::transaction(function () use ($order, $data): void {
            $order->update([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Safe only because the order is still a draft: nothing has been received, so
            // no received_quantity can be lost.
            $order->lines()->delete();
            $this->syncLines($order, $data['lines']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase order updated.')]);

        return to_route('purchase-orders.show', $order);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(PurchaseOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            $quantity = DecimalMath::quantity(DecimalMath::of($line['quantity']));
            $price = DecimalMath::cost(DecimalMath::of($line['unit_price']));

            $order->lines()->create([
                'product_id' => $line['product_id'],
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => DecimalMath::cost(DecimalMath::mul($quantity, $price)),
                'received_quantity' => '0.000',
            ]);
        }
    }

    private function assertEditable(PurchaseOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft purchase order can be edited. :reference is :status.', [
                    'reference' => $order->po_number,
                    'status' => $order->status->label(),
                ]),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::query()
                ->where('is_active', true)
                ->with('primaryUom:id,code')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'primary_uom_id'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'uom' => $product->primaryUom?->code,
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(PurchaseOrder $order): array
    {
        $ordered = DecimalMath::zero();
        $received = DecimalMath::zero();

        foreach ($order->lines as $line) {
            $ordered = DecimalMath::add($ordered, DecimalMath::of($line->quantity));
            $received = DecimalMath::add($received, DecimalMath::of($line->received_quantity));
        }

        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'supplier_code' => $order->supplier?->code,
            'supplier_name' => $order->supplier?->name,
            'warehouse_code' => $order->warehouse?->code,
            'order_date' => $order->order_date?->toDateString(),
            'expected_date' => $order->expected_date?->toDateString(),
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'material_total' => $order->materialTotal(),
            'ordered_quantity' => DecimalMath::quantity($ordered),
            'received_quantity' => DecimalMath::quantity($received),
            'remaining_quantity' => DecimalMath::quantity(
                DecimalMath::isNegative(DecimalMath::sub($ordered, $received))
                    ? DecimalMath::zero()
                    : DecimalMath::sub($ordered, $received)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(PurchaseOrder $order): array
    {
        return array_merge($this->summarise($order), [
            'supplier_id' => $order->supplier_id,
            'supplier_reference' => $order->supplier_reference,
            'notes' => $order->notes,
            'approved_by' => $order->approvedBy?->name,
            'approved_at' => $order->approved_at?->toDateTimeString(),
            'created_by' => $order->createdBy?->name,
            'can_approve' => $order->status->canApprove(),
            'can_cancel' => $order->status->canCancel(),
            'can_edit' => $order->status->isEditable(),
            'lines' => $order->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_code' => $line->product?->code,
                'product_name' => $line->product?->name,
                'uom' => $line->product?->primaryUom?->code,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
                'received_quantity' => $line->received_quantity,
                'outstanding_quantity' => $line->outstandingQuantity(),
            ])->values(),
            'goods_receipts' => $order->goodsReceipts->map(fn ($grn): array => [
                'id' => $grn->id,
                'grn_number' => $grn->grn_number,
                'receipt_date' => $grn->receipt_date?->toDateString(),
                'status' => $grn->status->value,
                'status_label' => $grn->status->label(),
            ])->values(),
        ]);
    }
}
