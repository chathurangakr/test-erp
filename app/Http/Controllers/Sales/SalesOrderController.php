<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\SaveSalesOrder;
use App\Enums\PricingBasis;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesOrderRequest;
use App\Http\Requests\Sales\UpdateSalesOrderRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales orders.
 *
 * A sales order creates no inventory movement; it records an intention to sell. Only a
 * DRAFT may be edited — approval turns it into a controlled document whose commercial
 * values are preserved.
 */
class SalesOrderController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly SaveSalesOrder $saveOrder) {}

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');
        $customerId = $request->integer('customer_id') ?: null;

        $orders = SalesOrder::query()
            ->with(['customer:id,code,name', 'warehouse:id,code', 'lines'])
            ->search($search)
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SalesOrder $order): array => $this->summarise($order));

        return Inertia::render('sales/sales-orders/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $status ?: '',
                'customer_id' => $customerId,
            ],
            'customers' => Customer::query()->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => SalesOrderStatus::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('sales/sales-orders/Create', $this->formOptions());
    }

    public function store(StoreSalesOrderRequest $request): RedirectResponse
    {
        $order = $this->saveOrder->handle(null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales order created.')]);

        return to_route('sales-orders.show', $order);
    }

    public function show(SalesOrder $order): Response
    {
        $order->load([
            'customer:id,code,name',
            'warehouse:id,code,name',
            'lines.product:id,code,name,primary_uom_id,secondary_uom_id',
            'lines.product.primaryUom:id,code',
            'lines.product.secondaryUom:id,code',
            'deliveries:id,delivery_number,deliveries.sales_order_id,delivery_date,status',
            'approvedBy:id,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('sales/sales-orders/Show', [
            'order' => $this->detail($order),
        ]);
    }

    public function edit(SalesOrder $order): Response
    {
        $this->saveOrder->assertEditable($order);

        $order->load('lines');

        return Inertia::render('sales/sales-orders/Edit', array_merge($this->formOptions(), [
            'order' => [
                'id' => $order->id,
                'so_number' => $order->so_number,
                'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id,
                'order_date' => $order->order_date?->toDateString(),
                'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
                'customer_reference' => $order->customer_reference,
                'notes' => $order->notes,
                'lines' => $order->lines->map(fn ($line): array => [
                    'product_id' => $line->product_id,
                    'quantity' => $line->quantity,
                    'secondary_quantity' => $line->secondary_quantity,
                    'pricing_basis' => $line->pricing_basis->value,
                    'unit_price' => $line->unit_price,
                ])->values(),
            ],
        ]));
    }

    public function update(UpdateSalesOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        $this->saveOrder->handle($order, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales order updated.')]);

        return to_route('sales-orders.show', $order);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'customers' => Customer::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::query()
                ->where('is_active', true)
                ->with(['primaryUom:id,code', 'secondaryUom:id,code'])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'primary_uom_id', 'secondary_uom_id'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'uom' => $product->primaryUom?->code,
                    'secondary_uom' => $product->secondaryUom?->code,
                ]),
            'pricingBases' => PricingBasis::options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(SalesOrder $order): array
    {
        $ordered = DecimalMath::zero();
        $delivered = DecimalMath::zero();

        foreach ($order->lines as $line) {
            $ordered = DecimalMath::add($ordered, DecimalMath::of($line->quantity));
            $delivered = DecimalMath::add($delivered, DecimalMath::of($line->delivered_quantity));
        }

        $remaining = DecimalMath::sub($ordered, $delivered);

        return [
            'id' => $order->id,
            'so_number' => $order->so_number,
            'customer_code' => $order->customer?->code,
            'customer_name' => $order->customer?->name,
            'warehouse_code' => $order->warehouse?->code,
            'order_date' => $order->order_date?->toDateString(),
            'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'sales_total' => $order->salesTotal(),
            'ordered_quantity' => DecimalMath::quantity($ordered),
            'delivered_quantity' => DecimalMath::quantity($delivered),
            'remaining_quantity' => DecimalMath::quantity(
                DecimalMath::isNegative($remaining) ? DecimalMath::zero() : $remaining
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(SalesOrder $order): array
    {
        return array_merge($this->summarise($order), [
            'customer_id' => $order->customer_id,
            'customer_reference' => $order->customer_reference,
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
                'secondary_uom' => $line->product?->secondaryUom?->code,
                'quantity' => $line->quantity,
                'secondary_quantity' => $line->secondary_quantity,
                'pricing_basis' => $line->pricing_basis->value,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
                'delivered_quantity' => $line->delivered_quantity,
                'outstanding_quantity' => $line->outstandingQuantity(),
            ])->values(),
            'deliveries' => $order->deliveries->map(fn ($delivery): array => [
                'id' => $delivery->id,
                'delivery_number' => $delivery->delivery_number,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'status' => $delivery->status->value,
                'status_label' => $delivery->status->label(),
            ])->values(),
        ]);
    }
}
