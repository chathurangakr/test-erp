<?php

namespace App\Http\Controllers\Receivables;

use App\Actions\Receivables\SaveCustomerReceipt;
use App\Enums\CustomerReceiptStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receivables\StoreCustomerReceiptRequest;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\BusinessClock;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function __construct(
        private readonly SaveCustomerReceipt $saveReceipt,
        private readonly AccountsReceivableService $receivables,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $customerId = $request->integer('customer_id') ?: null;

        $list = ListQuery::from($request)->period('receipt_date')->sortable([
            'number' => 'receipt_number',
            'customer' => fn (Builder $q, string $d) => $q->orderBy(Customer::select('code')->whereColumn('customers.id', 'customer_receipts.customer_id'), $d),
            'date' => 'receipt_date',
            'account' => fn (Builder $q, string $d) => $q->orderBy(Account::select('code')->whereColumn('accounts.id', 'customer_receipts.receipt_account_id'), $d),
            'reference' => 'reference',
            'status' => 'status',
            'amount' => 'amount',
        ], 'date');

        $receipts = $list->paginate(CustomerReceipt::query()
            ->with(['customer:id,code,name', 'receiptAccount:id,code,name', 'allocations'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id)))
            ->through(fn (CustomerReceipt $receipt): array => $this->summarise($receipt));

        return Inertia::render('finance/customer-receipts/Index', [
            'receipts' => $receipts,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
                'customer_id' => $customerId,
            ] + $list->filters(),
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
            'paymentMethods' => PaymentMethod::options(),
            // "Record payment" from an invoice pre-selects that invoice.
            'invoiceId' => $request->integer('invoice_id') ?: null,
            'customerId' => $customerId,
            'outstandingInvoices' => $customer === null ? [] : $this->outstandingFor($customer),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreCustomerReceiptRequest $request): RedirectResponse
    {
        $receipt = $this->saveReceipt->handle($request->validated());

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
                'account_kind' => $configuration->account_kind->value,
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
            'payment_method' => $receipt->payment_method?->value,
            'payment_method_label' => $receipt->payment_method?->label(),
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
            'cheque_number' => $receipt->cheque_number,
            'cheque_date' => $receipt->cheque_date?->toDateString(),
            'cheque_bank' => $receipt->cheque_bank,
            'created_by' => $receipt->createdBy?->name,
            'posted_by' => $receipt->postedBy?->name,
            'posted_at' => BusinessClock::display($receipt->posted_at),
            'reversed_by' => $receipt->reversedBy?->name,
            'reversed_at' => BusinessClock::display($receipt->reversed_at),
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
