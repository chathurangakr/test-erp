<?php

namespace App\Http\Controllers\Receivables;

use App\Actions\Receivables\GenerateReceivableReference;
use App\Enums\SalesInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receivables\StoreSalesInvoiceRequest;
use App\Http\Requests\Receivables\UpdateSalesInvoiceRequest;
use App\Models\Customer;
use App\Models\DeliveryLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\SalesBillingReconciliationService;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    private const PER_PAGE = 25;

    public function __construct(
        private readonly GenerateReceivableReference $references,
        private readonly AccountsReceivableService $receivables,
        private readonly SalesBillingReconciliationService $billing,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $customerId = $request->integer('customer_id') ?: null;
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();
        $overdueOnly = $request->boolean('overdue');

        $invoices = SalesInvoice::query()
            ->with(['customer:id,code,name', 'lines'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('invoice_date', '<=', $d))
            ->when($overdueOnly, fn (Builder $q) => $q
                ->whereDate('due_date', '<', now()->toDateString())
                ->whereIn('status', [SalesInvoiceStatus::Posted->value, SalesInvoiceStatus::PartiallyPaid->value]))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SalesInvoice $invoice): array => $this->summarise($invoice));

        return Inertia::render('finance/sales-invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
                'customer_id' => $customerId,
                'from' => $from,
                'to' => $to,
                'overdue' => $overdueOnly,
            ],
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
        $data = $request->validated();

        $invoice = DB::transaction(function () use ($data): SalesInvoice {
            $invoice = SalesInvoice::create([
                'invoice_number' => $this->references->forInvoice(Carbon::parse($data['invoice_date'])),
                'customer_id' => $data['customer_id'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'customer_reference' => $data['customer_reference'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => SalesInvoiceStatus::Draft,
                'created_by' => auth()->id(),
            ]);

            $this->writeLines($invoice, $data['lines']);

            return $invoice;
        });

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

    public function edit(SalesInvoice $salesInvoice): Response
    {
        $this->assertEditable($salesInvoice);

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
        $this->assertEditable($salesInvoice);

        $data = $request->validated();

        DB::transaction(function () use ($salesInvoice, $data): void {
            $salesInvoice->update([
                'customer_id' => $data['customer_id'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'customer_reference' => $data['customer_reference'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            // Safe only because the invoice is still a draft — nothing has reached the books.
            $salesInvoice->lines()->delete();

            $this->writeLines($salesInvoice, $data['lines']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales invoice updated.')]);

        return to_route('sales-invoices.show', $salesInvoice);
    }

    /**
     * Write the lines, copying each delivered line's product for reporting.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(SalesInvoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            $deliveryLine = DeliveryLine::find($line['delivery_line_id']);

            if ($deliveryLine === null) {
                throw ValidationException::withMessages([
                    'lines' => __('One of the selected delivery lines no longer exists.'),
                ]);
            }

            $invoice->lines()->create([
                'delivery_line_id' => $deliveryLine->id,
                'product_id' => $deliveryLine->product_id,
                'description' => $line['description'] ?? null,
                'amount' => DecimalMath::cost(DecimalMath::of((string) $line['amount'])),
            ]);
        }
    }

    private function assertEditable(SalesInvoice $invoice): void
    {
        if (! $invoice->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft sales invoice can be edited. :reference is :status.', [
                    'reference' => $invoice->invoice_number,
                    'status' => $invoice->status->label(),
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
                && $invoice->due_date?->isBefore(now()->startOfDay()) === true,
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
            'posted_at' => $invoice->posted_at?->toDateTimeString(),
            'reversed_by' => $invoice->reversedBy?->name,
            'reversed_at' => $invoice->reversed_at?->toDateTimeString(),
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
