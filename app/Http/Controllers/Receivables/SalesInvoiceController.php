<?php

namespace App\Http\Controllers\Receivables;

use App\Actions\Receivables\SaveSalesInvoice;
use App\Enums\SalesInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receivables\StoreSalesInvoiceRequest;
use App\Http\Requests\Receivables\UpdateSalesInvoiceRequest;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\SalesBillingReconciliationService;
use App\Support\BusinessClock;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales invoices.
 *
 * A draft is editable and is owed nothing. Posting is a separate action, because it is what
 * recognises the revenue and creates the receivable.
 *
 * There is no destroy route: a posted invoice is reversed, a draft is cancelled.
 */
class SalesInvoiceController extends Controller
{
    public function __construct(
        private readonly SaveSalesInvoice $saveInvoice,
        private readonly AccountsReceivableService $receivables,
        private readonly SalesBillingReconciliationService $billing,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $customerId = $request->integer('customer_id') ?: null;
        $overdueOnly = $request->boolean('overdue');
        $list = ListQuery::from($request)->period('invoice_date')->sortable([
            'number' => 'invoice_number',
            'customer' => fn (Builder $q, string $d) => $q->orderBy(Customer::select('code')->whereColumn('customers.id', 'sales_invoices.customer_id'), $d),
            'date' => 'invoice_date',
            'due' => 'due_date',
            'status' => 'status',
            'total' => fn (Builder $q, string $d) => $q->orderBy(SalesInvoiceLine::selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('sales_invoice_lines.sales_invoice_id', 'sales_invoices.id'), $d),
        ], 'date');

        $invoices = $list->paginate(SalesInvoice::query()
            ->with(['customer:id,code,name', 'lines'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when($overdueOnly, fn (Builder $q) => $q
                ->whereDate('due_date', '<', BusinessClock::todayString())
                ->whereIn('status', [SalesInvoiceStatus::Posted->value, SalesInvoiceStatus::PartiallyPaid->value])))
            ->through(fn (SalesInvoice $invoice): array => $this->summarise($invoice));

        return Inertia::render('finance/sales-invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
                'customer_id' => $customerId,
                'overdue' => $overdueOnly,
            ] + $list->filters(),
            'statuses' => SalesInvoiceStatus::options(),
            'customers' => Customer::query()->orderBy('code')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(Request $request): Response
    {
        $customerId = $request->integer('customer_id') ?: null;
        $customer = $customerId === null ? null : Customer::find($customerId);

        return Inertia::render('finance/sales-invoices/Create', array_merge($this->formOptions(), [
            'customerId' => $customerId,
            'billableLines' => $customer === null ? [] : $this->billing->billableLinesFor($customer->id),
            // The customer's agreed terms suggest a due date; the user may still change it.
            'creditDays' => $customer?->credit_days ?? 0,
        ]));
    }

    public function store(StoreSalesInvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->saveInvoice->handle(null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales invoice saved as draft.')]);

        return to_route('sales-invoices.show', $invoice);
    }

    public function show(SalesInvoice $salesInvoice): Response
    {
        $salesInvoice->load([
            'customer:id,code,name',
            'lines.product:id,code,name',
            'lines.deliveryLine.delivery:id,delivery_number,delivery_date',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'postedBy:id,name',
            'reversedBy:id,name',
            'allocations.receipt:id,receipt_number,receipt_date,status',
        ]);

        return Inertia::render('finance/sales-invoices/Show', [
            'invoice' => $this->detail($salesInvoice),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    /**
     * The invoice as the customer receives it, laid out for an A4 sheet.
     */
    public function print(SalesInvoice $salesInvoice): Response
    {
        $salesInvoice->load([
            'customer',
            'lines.product.primaryUom:id,code',
            'lines.product.secondaryUom:id,code',
            'lines.deliveryLine.delivery:id,delivery_number,delivery_date',
        ]);

        $customer = $salesInvoice->customer;

        return Inertia::render('finance/sales-invoices/Print', [
            'invoice' => array_merge($this->summarise($salesInvoice), [
                'description' => $salesInvoice->description,
                'customer' => [
                    'code' => $customer?->code,
                    'name' => $customer?->name,
                    'contact_person' => $customer?->contact_person,
                    'address' => collect([$customer?->address_line_1, $customer?->address_line_2, $customer?->city])
                        ->filter()
                        ->implode(', '),
                    'phone' => $customer?->phone,
                    'email' => $customer?->email,
                    'tax_number' => $customer?->tax_number,
                ],
                'lines' => $salesInvoice->lines->map(function (SalesInvoiceLine $line): array {
                    $delivered = $line->deliveryLine;
                    $isSecondary = $delivered?->pricing_basis->isSecondary() ?? false;

                    return [
                        'id' => $line->id,
                        'product_code' => $line->product?->code,
                        'product_name' => $line->product?->name,
                        'description' => $line->description,
                        'delivery_number' => $delivered?->delivery?->delivery_number,
                        'delivery_date' => $delivered?->delivery?->delivery_date?->toDateString(),
                        'quantity' => $delivered?->sellingQuantity(),
                        'uom' => $isSecondary ? $line->product?->secondaryUom?->code : $line->product?->primaryUom?->code,
                        'unit_price' => $delivered?->unit_price,
                        'delivered_value' => $delivered === null ? null : $this->billing->originalValue($delivered),
                        'amount' => $line->amount,
                    ];
                })->values(),
            ]),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function edit(SalesInvoice $salesInvoice): Response
    {
        $this->saveInvoice->assertEditable($salesInvoice);

        $salesInvoice->load('lines');

        return Inertia::render('finance/sales-invoices/Edit', array_merge($this->formOptions(), [
            'customerId' => $salesInvoice->customer_id,
            'billableLines' => $this->billing->billableLinesFor($salesInvoice->customer_id),
            'creditDays' => $salesInvoice->customer?->credit_days ?? 0,
            'invoice' => [
                'id' => $salesInvoice->id,
                'invoice_number' => $salesInvoice->invoice_number,
                'customer_id' => $salesInvoice->customer_id,
                'invoice_date' => $salesInvoice->invoice_date?->toDateString(),
                'due_date' => $salesInvoice->due_date?->toDateString(),
                'customer_reference' => $salesInvoice->customer_reference,
                'description' => $salesInvoice->description,
                'lines' => $salesInvoice->lines->map(fn (SalesInvoiceLine $line): array => [
                    'delivery_line_id' => $line->delivery_line_id,
                    'description' => $line->description,
                    'amount' => $line->amount,
                ])->values(),
            ],
        ]));
    }

    public function update(UpdateSalesInvoiceRequest $request, SalesInvoice $salesInvoice): RedirectResponse
    {
        $this->saveInvoice->handle($salesInvoice, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales invoice updated.')]);

        return to_route('sales-invoices.show', $salesInvoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'customers' => Customer::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'credit_days']),
            'currency' => config('accounting.base_currency'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(SalesInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer_id' => $invoice->customer_id,
            'customer_code' => $invoice->customer?->code,
            'customer_name' => $invoice->customer?->name,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'customer_reference' => $invoice->customer_reference,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'total' => $this->receivables->invoiceTotal($invoice),
            'received' => $this->receivables->receivedAmount($invoice),
            'outstanding' => $this->receivables->outstanding($invoice),
            'is_overdue' => $invoice->status->isOutstanding()
                && BusinessClock::isPastDue($invoice->due_date),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(SalesInvoice $invoice): array
    {
        return array_merge($this->summarise($invoice), [
            'description' => $invoice->description,
            'created_by' => $invoice->createdBy?->name,
            'posted_by' => $invoice->postedBy?->name,
            'posted_at' => BusinessClock::display($invoice->posted_at),
            'reversed_by' => $invoice->reversedBy?->name,
            'reversed_at' => BusinessClock::display($invoice->reversed_at),
            'can_post' => $invoice->status->canPost(),
            'can_edit' => $invoice->status->isEditable(),
            'can_cancel' => $invoice->status->canCancel(),
            'can_reverse' => $invoice->status->canReverse() && $invoice->reversal_journal_entry_id === null,
            'journal' => $invoice->journalEntry === null ? null : [
                'id' => $invoice->journalEntry->id,
                'journal_number' => $invoice->journalEntry->journal_number,
                'lines' => $invoice->journalEntry->lines->map(fn ($line): array => [
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'description' => $line->description,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ])->values(),
            ],
            'reversal_journal_number' => $invoice->reversalJournalEntry?->journal_number,
            'lines' => $invoice->lines->map(fn (SalesInvoiceLine $line): array => [
                'id' => $line->id,
                'delivery_line_id' => $line->delivery_line_id,
                'delivery_id' => $line->deliveryLine?->delivery_id,
                'delivery_number' => $line->deliveryLine?->delivery?->delivery_number,
                'delivery_date' => $line->deliveryLine?->delivery?->delivery_date?->toDateString(),
                'product_code' => $line->product?->code,
                'product_name' => $line->product?->name,
                'delivered_value' => $line->deliveryLine === null ? null : $this->billing->originalValue($line->deliveryLine),
                'description' => $line->description,
                'amount' => $line->amount,
            ])->values(),
            'receipts' => $invoice->allocations->map(fn ($allocation): array => [
                'id' => $allocation->id,
                'receipt_id' => $allocation->receipt?->id,
                'receipt_number' => $allocation->receipt?->receipt_number,
                'receipt_date' => $allocation->receipt?->receipt_date?->toDateString(),
                'status' => $allocation->receipt?->status->value,
                'status_label' => $allocation->receipt?->status->label(),
                'amount' => $allocation->amount,
            ])->values(),
        ]);
    }
}
