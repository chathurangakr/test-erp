<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Masters\CreateCustomer;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Delivery;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer master.
 *
 * One customer covers every product the factory sells. What a customer owes is shown
 * here but never stored here — it is derived from posted invoices and receipts.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly AccountsReceivableService $receivables) {}

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');
        $status = (string) $request->string('status');

        $list = ListQuery::from($request)->sortable([
            'code' => 'code',
            'name' => 'name',
            'contact' => 'contact_person',
            'phone' => 'phone',
            'city' => 'city',
            'credit_limit' => 'credit_limit',
            'status' => 'is_active',
        ], 'code', 'asc');

        $customers = $list->paginate(Customer::query()
            ->search($search)
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false)));

        return Inertia::render('customers/Index', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
                'status' => $status ?: 'all',
            ] + $list->filters(),
        ]);
    }

    public function create(CreateCustomer $createCustomer): Response
    {
        return Inertia::render('customers/Create', [
            // A preview only: the code is taken when the customer is saved.
            'nextCode' => $createCustomer->nextCode(),
        ]);
    }

    public function store(StoreCustomerRequest $request, CreateCustomer $createCustomer): RedirectResponse
    {
        $customer = $createCustomer->handle($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer created.')]);

        return to_route('customers.show', $customer);
    }

    public function show(Customer $customer): Response
    {
        return Inertia::render('customers/Show', [
            'customer' => $customer,
            'salesHistory' => $this->salesHistory($customer),
            'receivables' => $this->receivablesSummary($customer),
        ]);
    }

    /**
     * What this customer owes, derived from posted invoices and receipts.
     *
     * Deliberately NOT stored on the customer: a master record holds no balance, and this
     * figure is computed from the documents every time it is asked for. `credit_limit` is
     * an agreed term, not a balance.
     *
     * @return array<string, mixed>
     */
    private function receivablesSummary(Customer $customer): array
    {
        $invoices = SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->with('lines')
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (SalesInvoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'status_label' => $invoice->status->label(),
                'total' => $this->receivables->invoiceTotal($invoice),
                'outstanding' => $this->receivables->outstanding($invoice),
            ]);

        $receipts = CustomerReceipt::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (CustomerReceipt $receipt): array => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'receipt_date' => $receipt->receipt_date?->toDateString(),
                'status_label' => $receipt->status->label(),
                'amount' => $receipt->amount,
            ]);

        // Overdue is what is outstanding on invoices already past their due date.
        $overdue = DecimalMath::zero();

        foreach ($this->receivables->outstandingInvoices($customer) as $invoice) {
            if (BusinessClock::isPastDue($invoice->due_date)) {
                $overdue = DecimalMath::add($overdue, $this->receivables->outstanding($invoice));
            }
        }

        return [
            'outstanding' => $this->receivables->customerOutstanding($customer),
            'overdue' => DecimalMath::cost($overdue),
            'currency' => config('accounting.base_currency'),
            'invoices' => $invoices,
            'receipts' => $receipts,
        ];
    }

    /**
     * Recent selling activity for this customer.
     *
     * Physical selling activity only. What the customer owes is a separate, derived
     * figure — see receivablesSummary().
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
