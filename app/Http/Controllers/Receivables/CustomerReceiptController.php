<?php

namespace App\Http\Controllers\Receivables;

use App\Actions\Receivables\GenerateReceivableReference;
use App\Enums\CustomerReceiptStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receivables\StoreCustomerReceiptRequest;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer receipts.
 *
 * A receipt is always allocated to invoices — Version 1 has no deposits or receipts on
 * account — so the form shows what the customer owes and the allocations must add up.
 */
class CustomerReceiptController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly GenerateReceivableReference $references,
        private readonly AccountsReceivableService $receivables,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $customerId = $request->integer('customer_id') ?: null;

        $receipts = CustomerReceipt::query()
            ->with(['customer:id,code,name', 'receiptAccount:id,code,name', 'allocations'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (CustomerReceipt $receipt): array => $this->summarise($receipt));

        return Inertia::render('finance/customer-receipts/Index', [
            'receipts' => $receipts,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
                'customer_id' => $customerId,
            ],
            'statuses' => CustomerReceiptStatus::options(),
            'customers' => Customer::query()->orderBy('code')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(Request $request): Response
    {
        $customerId = $request->integer('customer_id') ?: null;
        $customer = $customerId === null ? null : Customer::find($customerId);

        return Inertia::render('finance/customer-receipts/Create', [
            'customers' => Customer::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            // Only accounts the factory has configured as cash or bank. Being an asset
            // account is not enough — see AccountingPolicy::assertCashBankAccount().
            'receiptAccounts' => $this->cashBankOptions(),
            'customerId' => $customerId,
            'outstandingInvoices' => $customer === null ? [] : $this->outstandingFor($customer),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreCustomerReceiptRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $receipt = DB::transaction(function () use ($data): CustomerReceipt {
            $receipt = CustomerReceipt::create([
                'receipt_number' => $this->references->forReceipt(Carbon::parse($data['receipt_date'])),
                'customer_id' => $data['customer_id'],
                'receipt_date' => $data['receipt_date'],
                'receipt_account_id' => $data['receipt_account_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'amount' => DecimalMath::cost(DecimalMath::of((string) $data['amount'])),
                'status' => CustomerReceiptStatus::Draft,
                'created_by' => auth()->id(),
            ]);

            foreach ($data['allocations'] as $allocation) {
                $receipt->allocations()->create([
                    'sales_invoice_id' => $allocation['sales_invoice_id'],
                    'amount' => DecimalMath::cost(DecimalMath::of((string) $allocation['amount'])),
                ]);
            }

            return $receipt;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer receipt saved as draft.')]);

        return to_route('customer-receipts.show', $receipt);
    }

    public function show(CustomerReceipt $customerReceipt): Response
    {
        $customerReceipt->load([
            'customer:id,code,name',
            'receiptAccount:id,code,name',
            'allocations.invoice:id,invoice_number,due_date,status',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'postedBy:id,name',
            'reversedBy:id,name',
        ]);

        return Inertia::render('finance/customer-receipts/Show', [
            'receipt' => $this->detail($customerReceipt),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    /**
     * The accounts money may legitimately be received into.
     *
     * @return list<array<string, mixed>>
     */
    private function cashBankOptions(): array
    {
        return CashBankAccount::query()
            ->with('account:id,code,name,is_active')
            ->where('is_active', true)
            ->get()
            ->filter(fn (CashBankAccount $configuration): bool => $configuration->account?->is_active === true)
            ->sortBy(fn (CashBankAccount $configuration): string => (string) $configuration->account?->code)
            ->map(fn (CashBankAccount $configuration): array => [
                'id' => $configuration->account_id,
                'code' => $configuration->account?->code,
                'name' => $configuration->display_name,
                'kind' => $configuration->account_kind->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * What one customer still owes, invoice by invoice.
     *
     * @return list<array<string, mixed>>
     */
    private function outstandingFor(Customer $customer): array
    {
        return $this->receivables->outstandingInvoices($customer)
            ->map(fn (SalesInvoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_reference' => $invoice->customer_reference,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total' => $this->receivables->invoiceTotal($invoice),
                'received' => $this->receivables->receivedAmount($invoice),
                'outstanding' => $this->receivables->outstanding($invoice),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(CustomerReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'customer_code' => $receipt->customer?->code,
            'customer_name' => $receipt->customer?->name,
            'receipt_date' => $receipt->receipt_date?->toDateString(),
            'receipt_account' => $receipt->receiptAccount === null
                ? null
                : $receipt->receiptAccount->code.' — '.$receipt->receiptAccount->name,
            'reference' => $receipt->reference,
            'amount' => $receipt->amount,
            'allocated' => $receipt->allocatedTotal(),
            'status' => $receipt->status->value,
            'status_label' => $receipt->status->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(CustomerReceipt $receipt): array
    {
        return array_merge($this->summarise($receipt), [
            'notes' => $receipt->notes,
            'created_by' => $receipt->createdBy?->name,
            'posted_by' => $receipt->postedBy?->name,
            'posted_at' => $receipt->posted_at?->toDateTimeString(),
            'reversed_by' => $receipt->reversedBy?->name,
            'reversed_at' => $receipt->reversed_at?->toDateTimeString(),
            'can_post' => $receipt->status->canPost(),
            'can_cancel' => $receipt->status->canCancel(),
            'can_reverse' => $receipt->status->canReverse() && $receipt->reversal_journal_entry_id === null,
            'journal' => $receipt->journalEntry === null ? null : [
                'id' => $receipt->journalEntry->id,
                'journal_number' => $receipt->journalEntry->journal_number,
                'lines' => $receipt->journalEntry->lines->map(fn ($line): array => [
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ])->values(),
            ],
            'reversal_journal_number' => $receipt->reversalJournalEntry?->journal_number,
            'allocations' => $receipt->allocations->map(fn ($allocation): array => [
                'id' => $allocation->id,
                'invoice_id' => $allocation->invoice?->id,
                'invoice_number' => $allocation->invoice?->invoice_number,
                'due_date' => $allocation->invoice?->due_date?->toDateString(),
                'invoice_status' => $allocation->invoice?->status->label(),
                'amount' => $allocation->amount,
            ])->values(),
        ]);
    }
}
