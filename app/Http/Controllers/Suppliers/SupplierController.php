<?php

namespace App\Http\Controllers\Suppliers;

use App\Enums\GoodsReceiptStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier master.
 *
 * One supplier covers every material the factory buys. Purchases and payments will
 * reference suppliers from their own tables in later phases.
 */
class SupplierController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');

        $suppliers = Supplier::query()
            ->search($search)
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => [
                'search' => $search,
                'status' => $status ?: 'all',
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('suppliers/Create');
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $supplier = Supplier::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier created.')]);

        return to_route('suppliers.show', $supplier);
    }

    public function show(Supplier $supplier): Response
    {
        return Inertia::render('suppliers/Show', [
            'supplier' => $supplier,
            'purchaseHistory' => $this->purchaseHistory($supplier),
        ]);
    }

    /**
     * Recent purchasing activity for this supplier.
     *
     * Deliberately NOT a payable balance: amounts owed belong to the finance module and
     * must be derived from posted transactions there, never stored on the supplier.
     *
     * @return array<string, mixed>
     */
    private function purchaseHistory(Supplier $supplier): array
    {
        $orders = PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'po_number', 'order_date', 'status']);

        $receipts = GoodsReceipt::query()
            ->where('supplier_id', $supplier->id)
            ->with('lines:id,goods_receipt_id,quantity,unit_price')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        // Total received value counts POSTED receipts only — drafts have moved no stock,
        // and cancelled/reversed receipts have been undone.
        $totalReceived = GoodsReceipt::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', GoodsReceiptStatus::Posted)
            ->with('lines:id,goods_receipt_id,quantity,unit_price,allocated_landed_cost')
            ->get()
            ->reduce(function (string $carry, GoodsReceipt $receipt): string {
                foreach ($receipt->lines as $line) {
                    $carry = DecimalMath::add(
                        $carry,
                        DecimalMath::add(
                            DecimalMath::mul(DecimalMath::of($line->quantity), DecimalMath::of($line->unit_price)),
                            DecimalMath::of($line->allocated_landed_cost),
                        ),
                    );
                }

                return $carry;
            }, DecimalMath::zero());

        return [
            'purchase_orders' => $orders->map(fn (PurchaseOrder $order): array => [
                'id' => $order->id,
                'po_number' => $order->po_number,
                'order_date' => $order->order_date?->toDateString(),
                'status_label' => $order->status->label(),
            ])->values(),
            'goods_receipts' => $receipts->map(fn (GoodsReceipt $receipt): array => [
                'id' => $receipt->id,
                'grn_number' => $receipt->grn_number,
                'receipt_date' => $receipt->receipt_date?->toDateString(),
                'status_label' => $receipt->status->label(),
            ])->values(),
            'total_received_value' => DecimalMath::cost($totalReceived),
        ];
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('suppliers/Edit', [
            'supplier' => $supplier,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier updated.')]);

        return to_route('suppliers.show', $supplier);
    }
}
