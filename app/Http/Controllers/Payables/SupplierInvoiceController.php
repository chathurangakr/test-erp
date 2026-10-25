<?php

namespace App\Http\Controllers\Payables;

use App\Actions\Payables\SaveSupplierInvoice;
use App\Enums\AccountType;
use App\Enums\SupplierInvoiceLineType;
use App\Enums\SupplierInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payables\StoreSupplierInvoiceRequest;
use App\Http\Requests\Payables\UpdateSupplierInvoiceRequest;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\GrniReconciliationService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier invoices.
 *
 * A draft is editable and owes nothing. Posting is a separate action, because it is what
 * creates the payable and clears the goods-received accrual.
 *
 * There is no destroy route: a posted invoice is reversed, a draft is cancelled.
 */
class SupplierInvoiceController extends Controller
{
    public function __construct(
        private readonly SaveSupplierInvoice $saveInvoice,
        private readonly AccountsPayableService $payables,
        private readonly GrniReconciliationService $grni,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $supplierId = $request->integer('supplier_id') ?: null;
        $overdueOnly = $request->boolean('overdue');
        $list = ListQuery::from($request)->period('invoice_date')->sortable([
            'number' => 'internal_invoice_number',
            'supplier_number' => 'supplier_invoice_number',
            'supplier' => fn (Builder $q, string $d) => $q->orderBy(Supplier::select('code')->whereColumn('suppliers.id', 'supplier_invoices.supplier_id'), $d),
            'date' => 'invoice_date',
            'due' => 'due_date',
            'status' => 'status',
            'total' => fn (Builder $q, string $d) => $q->orderBy(SupplierInvoiceLine::selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('supplier_invoice_lines.supplier_invoice_id', 'supplier_invoices.id'), $d),
        ], 'date');

        $invoices = $list->paginate(SupplierInvoice::query()
            ->with(['supplier:id,code,name', 'lines'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($supplierId, fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->when($overdueOnly, fn (Builder $q) => $q
                ->whereDate('due_date', '<', BusinessClock::todayString())
                ->whereIn('status', [SupplierInvoiceStatus::Posted->value, SupplierInvoiceStatus::PartiallyPaid->value])))
            ->through(fn (SupplierInvoice $invoice): array => $this->summarise($invoice));

        return Inertia::render('finance/supplier-invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
                'supplier_id' => $supplierId,
                'overdue' => $overdueOnly,
            ] + $list->filters(),
            'statuses' => SupplierInvoiceStatus::options(),
            'suppliers' => Supplier::query()->orderBy('code')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('finance/supplier-invoices/Create', $this->formOptions());
    }

    public function store(StoreSupplierInvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->saveInvoice->handle(null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier invoice saved as draft.')]);

        return to_route('supplier-invoices.show', $invoice);
    }

    public function show(SupplierInvoice $supplierInvoice): Response
    {
        $supplierInvoice->load([
            'supplier:id,code,name',
            'lines.goodsReceipt:id,grn_number,receipt_date',
            'lines.debitAccount:id,code,name',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'postedBy:id,name',
            'reversedBy:id,name',
            'allocations.payment:id,payment_number,payment_date,status',
        ]);

        return Inertia::render('finance/supplier-invoices/Show', [
            'invoice' => $this->detail($supplierInvoice),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function edit(SupplierInvoice $supplierInvoice): Response
    {
        $this->saveInvoice->assertEditable($supplierInvoice);

        $supplierInvoice->load('lines');

        return Inertia::render('finance/supplier-invoices/Edit', array_merge($this->formOptions(), [
            'invoice' => [
                'id' => $supplierInvoice->id,
                'internal_invoice_number' => $supplierInvoice->internal_invoice_number,
                'supplier_id' => $supplierInvoice->supplier_id,
                'supplier_invoice_number' => $supplierInvoice->supplier_invoice_number,
                'invoice_date' => $supplierInvoice->invoice_date?->toDateString(),
                'due_date' => $supplierInvoice->due_date?->toDateString(),
                'description' => $supplierInvoice->description,
                'lines' => $supplierInvoice->lines->map(fn (SupplierInvoiceLine $line): array => [
                    'line_type' => $line->line_type->value,
                    'goods_receipt_id' => $line->goods_receipt_id,
                    'debit_account_id' => $line->debit_account_id,
                    'description' => $line->description,
                    'amount' => $line->amount,
                ])->values(),
            ],
        ]));
    }

    public function update(UpdateSupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice): RedirectResponse
    {
        $this->saveInvoice->handle($supplierInvoice, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier invoice updated.')]);

        return to_route('supplier-invoices.show', $supplierInvoice);
    }

    /**
     * Everything the form needs, including which receipts still have accrual to invoice.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $matchable = GoodsReceipt::query()
            ->with('supplier:id,code,name')
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (GoodsReceipt $receipt): ?array {
                $original = $this->grni->originalAccrual($receipt);

                if (! DecimalMath::isPositive($original)) {
                    return null;
                }

                $matched = $this->grni->matchedAmount($receipt);
                $remaining = DecimalMath::cost(DecimalMath::sub($original, $matched));

                if (! DecimalMath::isPositive($remaining)) {
                    return null;
                }

                return [
                    'id' => $receipt->id,
                    'grn_number' => $receipt->grn_number,
                    'receipt_date' => $receipt->receipt_date?->toDateString(),
                    'supplier_code' => $receipt->supplier?->code,
                    'original_accrual' => $original,
                    'matched_amount' => $matched,
                    'remaining_amount' => $remaining,
                ];
            })
            ->filter()
            ->values();

        return [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'goodsReceipts' => $matchable,
            // Ordinary expense accounts only: a direct-expense line must never be a route to
            // an inventory or payables control account.
            'expenseAccounts' => Account::query()
                ->where('is_active', true)
                ->where('account_type', AccountType::Expense)
                ->where('allow_manual_posting', true)
                ->where('is_control_account', false)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'lineTypes' => SupplierInvoiceLineType::options(),
            'currency' => config('accounting.base_currency'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(SupplierInvoice $invoice): array
    {
        $total = $this->payables->invoiceTotal($invoice);
        $paid = $this->payables->paidAmount($invoice);

        return [
            'id' => $invoice->id,
            'internal_invoice_number' => $invoice->internal_invoice_number,
            'supplier_invoice_number' => $invoice->supplier_invoice_number,
            'supplier_id' => $invoice->supplier_id,
            'supplier_code' => $invoice->supplier?->code,
            'supplier_name' => $invoice->supplier?->name,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'total' => $total,
            'paid' => $paid,
            'outstanding' => $this->payables->outstanding($invoice),
            'is_overdue' => $invoice->status->isOutstanding()
                && BusinessClock::isPastDue($invoice->due_date),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(SupplierInvoice $invoice): array
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
            'lines' => $invoice->lines->map(fn (SupplierInvoiceLine $line): array => [
                'id' => $line->id,
                'line_type' => $line->line_type->value,
                'line_type_label' => $line->line_type->label(),
                'grn_number' => $line->goodsReceipt?->grn_number,
                'goods_receipt_id' => $line->goods_receipt_id,
                'account_code' => $line->debitAccount?->code,
                'account_name' => $line->debitAccount?->name,
                'description' => $line->description,
                'amount' => $line->amount,
            ])->values(),
            'payments' => $invoice->allocations->map(fn ($allocation): array => [
                'id' => $allocation->id,
                'payment_id' => $allocation->payment?->id,
                'payment_number' => $allocation->payment?->payment_number,
                'payment_date' => $allocation->payment?->payment_date?->toDateString(),
                'status' => $allocation->payment?->status->value,
                'status_label' => $allocation->payment?->status->label(),
                'amount' => $allocation->amount,
            ])->values(),
        ]);
    }
}
