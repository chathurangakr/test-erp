<?php

namespace App\Http\Controllers\Payables;

use App\Actions\Payables\SaveSupplierPayment;
use App\Enums\PaymentMethod;
use App\Enums\SupplierPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payables\StoreSupplierPaymentRequest;
use App\Models\CashBankAccount;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\Accounting\AccountsPayableService;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier payments.
 *
 * A payment is always allocated to invoices — Version 1 has no payments on account — so the
 * form shows what the supplier is owed and the allocations must add up to the payment.
 */
class SupplierPaymentController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly SaveSupplierPayment $savePayment,
        private readonly AccountsPayableService $payables,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $supplierId = $request->integer('supplier_id') ?: null;

        $payments = SupplierPayment::query()
            ->with(['supplier:id,code,name', 'paymentAccount:id,code,name', 'allocations'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($supplierId, fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SupplierPayment $payment): array => $this->summarise($payment));

        return Inertia::render('finance/supplier-payments/Index', [
            'payments' => $payments,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
                'supplier_id' => $supplierId,
            ],
            'statuses' => SupplierPaymentStatus::options(),
            'suppliers' => Supplier::query()->orderBy('code')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(Request $request): Response
    {
        $supplierId = $request->integer('supplier_id') ?: null;
        $supplier = $supplierId === null ? null : Supplier::find($supplierId);

        return Inertia::render('finance/supplier-payments/Create', [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            // Only accounts the factory has configured as cash or bank. Being an asset
            // account is not enough — see AccountingPolicy::assertCashBankAccount().
            'paymentAccounts' => $this->cashBankOptions(),
            'paymentMethods' => PaymentMethod::options(),
            // "Record payment" from an invoice pre-selects that invoice.
            'invoiceId' => $request->integer('invoice_id') ?: null,
            'supplierId' => $supplierId,
            'outstandingInvoices' => $supplier === null ? [] : $this->outstandingFor($supplier),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreSupplierPaymentRequest $request): RedirectResponse
    {
        $payment = $this->savePayment->handle($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier payment saved as draft.')]);

        return to_route('supplier-payments.show', $payment);
    }

    public function show(SupplierPayment $supplierPayment): Response
    {
        $supplierPayment->load([
            'supplier:id,code,name',
            'paymentAccount:id,code,name',
            'allocations.invoice:id,internal_invoice_number,supplier_invoice_number,due_date,status',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'postedBy:id,name',
            'reversedBy:id,name',
        ]);

        return Inertia::render('finance/supplier-payments/Show', [
            'payment' => $this->detail($supplierPayment),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    /**
     * What one supplier still owes, invoice by invoice.
     *
     * @return list<array<string, mixed>>
     */
    private function outstandingFor(Supplier $supplier): array
    {
        return $this->payables->outstandingInvoices($supplier)
            ->map(fn (SupplierInvoice $invoice): array => [
                'id' => $invoice->id,
                'internal_invoice_number' => $invoice->internal_invoice_number,
                'supplier_invoice_number' => $invoice->supplier_invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total' => $this->payables->invoiceTotal($invoice),
                'paid' => $this->payables->paidAmount($invoice),
                'outstanding' => $this->payables->outstanding($invoice),
            ])
            ->values()
            ->all();
    }

    /**
     * The accounts money may legitimately be paid out of.
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
     * @return array<string, mixed>
     */
    private function summarise(SupplierPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'supplier_code' => $payment->supplier?->code,
            'supplier_name' => $payment->supplier?->name,
            'payment_date' => $payment->payment_date?->toDateString(),
            'payment_account' => $payment->paymentAccount === null
                ? null
                : $payment->paymentAccount->code.' — '.$payment->paymentAccount->name,
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'payment_method' => $payment->payment_method?->value,
            'payment_method_label' => $payment->payment_method?->label(),
            'allocated' => $payment->allocatedTotal(),
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(SupplierPayment $payment): array
    {
        return array_merge($this->summarise($payment), [
            'notes' => $payment->notes,
            'cheque_number' => $payment->cheque_number,
            'cheque_date' => $payment->cheque_date?->toDateString(),
            'cheque_bank' => $payment->cheque_bank,
            'created_by' => $payment->createdBy?->name,
            'posted_by' => $payment->postedBy?->name,
            'posted_at' => BusinessClock::display($payment->posted_at),
            'reversed_by' => $payment->reversedBy?->name,
            'reversed_at' => BusinessClock::display($payment->reversed_at),
            'can_post' => $payment->status->canPost(),
            'can_cancel' => $payment->status->canCancel(),
            'can_reverse' => $payment->status->canReverse() && $payment->reversal_journal_entry_id === null,
            'journal' => $payment->journalEntry === null ? null : [
                'id' => $payment->journalEntry->id,
                'journal_number' => $payment->journalEntry->journal_number,
                'lines' => $payment->journalEntry->lines->map(fn ($line): array => [
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ])->values(),
            ],
            'reversal_journal_number' => $payment->reversalJournalEntry?->journal_number,
            'allocations' => $payment->allocations->map(fn ($allocation): array => [
                'id' => $allocation->id,
                'invoice_id' => $allocation->invoice?->id,
                'internal_invoice_number' => $allocation->invoice?->internal_invoice_number,
                'supplier_invoice_number' => $allocation->invoice?->supplier_invoice_number,
                'due_date' => $allocation->invoice?->due_date?->toDateString(),
                'invoice_status' => $allocation->invoice?->status->label(),
                'amount' => $allocation->amount,
            ])->values(),
        ]);
    }
}
