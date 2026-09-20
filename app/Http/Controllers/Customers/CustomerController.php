<?php

namespace App\Http\Controllers\Customers;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\SalesOrder;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer master.
 *
 * One customer covers every product the factory sells. Balances and receivables are
 * deliberately not implemented here — they belong to the finance module.
 */
class CustomerController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');

        $customers = Customer::query()
            ->search($search)
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('customers/Index', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
                'status' => $status ?: 'all',
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('customers/Create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = Customer::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer created.')]);

        return to_route('customers.show', $customer);
    }

    public function show(Customer $customer): Response
    {
        return Inertia::render('customers/Show', [
            'customer' => $customer,
            'salesHistory' => $this->salesHistory($customer),
        ]);
    }

    /**
     * Recent selling activity for this customer.
     *
     * Deliberately NOT a receivable balance: amounts owed belong to the finance module and
     * must be derived from posted transactions there, never stored on the customer.
     *
     * @return array<string, mixed>
     */
    private function salesHistory(Customer $customer): array
    {
        $orders = SalesOrder::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'so_number', 'order_date', 'status']);

        $deliveries = Delivery::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'delivery_number', 'delivery_date', 'status']);

        // Delivered value counts POSTED deliveries only — drafts moved no stock, and
        // reversed or cancelled ones have been undone.
        $deliveredValue = Delivery::query()
            ->where('customer_id', $customer->id)
            ->where('status', DeliveryStatus::Posted)
            ->with('lines:id,delivery_id,sales_value')
            ->get()
            ->reduce(function (string $carry, Delivery $delivery): string {
                foreach ($delivery->lines as $line) {
                    $carry = DecimalMath::add($carry, DecimalMath::of($line->sales_value));
                }

                return $carry;
            }, DecimalMath::zero());

        return [
            'sales_orders' => $orders->map(fn (SalesOrder $order): array => [
                'id' => $order->id,
                'so_number' => $order->so_number,
                'order_date' => $order->order_date?->toDateString(),
                'status_label' => $order->status->label(),
            ])->values(),
            'deliveries' => $deliveries->map(fn (Delivery $delivery): array => [
                'id' => $delivery->id,
                'delivery_number' => $delivery->delivery_number,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'status_label' => $delivery->status->label(),
            ])->values(),
            'delivered_value' => DecimalMath::cost($deliveredValue),
        ];
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/Edit', [
            'customer' => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer updated.')]);

        return to_route('customers.show', $customer);
    }
}
