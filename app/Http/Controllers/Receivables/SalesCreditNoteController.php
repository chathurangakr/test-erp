<?php

namespace App\Http\Controllers\Receivables;

use App\Actions\Receivables\RecordSalesCreditNote;
use App\Actions\Receivables\ReverseSalesCreditNote;
use App\Enums\PaymentMethod;
use App\Enums\SalesCreditNoteKind;
use App\Enums\SalesCreditNoteSettlement;
use App\Enums\SalesInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receivables\StoreSalesCreditNoteRequest;
use App\Models\Customer;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\CashDocumentOptions;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Credit notes: discounts and quality allowances on an invoice, with no goods coming
 * back — reducing what the customer owes, or as money back for an invoice already paid. Recorded in one step and corrected by reversal. See
 * docs/ACCOUNTING_RULES.md §33.
 */
class SalesCreditNoteController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly AccountsReceivableService $receivables,
        private readonly CashDocumentOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $customerId = $request->integer('customer_id') ?: null;

        return Inertia::render('finance/credit-notes/Index', [
            'notes' => SalesCreditNote::query()
                ->with(['customer:id,code,name', 'invoice:id,invoice_number'])
                ->when($customerId, fn ($query, int $id) => $query->where('customer_id', $id))
                ->orderByDesc('credit_date')
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(fn (SalesCreditNote $note): array => $this->summarise($note)),
            'filters' => ['customer_id' => $customerId],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('finance/credit-notes/Create', [
            // Every invoice still owed something or with money paid on it that could be given back.
            'invoices' => SalesInvoice::query()
                ->with('customer:id,code,name')
                ->whereIn('status', [SalesInvoiceStatus::Posted->value, SalesInvoiceStatus::PartiallyPaid->value, SalesInvoiceStatus::Paid->value])
                ->orderByDesc('invoice_date')
                ->limit(500)
                ->get()
                ->map(fn (SalesInvoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date->toDateString(),
                    'customer' => $invoice->customer?->name,
                    'outstanding' => $this->receivables->outstanding($invoice),
                    'refundable' => $this->receivables->refundableAmount($invoice),
                ])
                ->filter(fn (array $invoice): bool => DecimalMath::isPositive($invoice['outstanding']) || DecimalMath::isPositive($invoice['refundable']))
                ->values(),
            'invoiceId' => $request->integer('sales_invoice_id') ?: null,
            'kinds' => SalesCreditNoteKind::options(),
            'settlements' => SalesCreditNoteSettlement::options(),
            'accounts' => $this->options->cashBankAccounts(),
            'paymentMethods' => PaymentMethod::options(),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreSalesCreditNoteRequest $request, RecordSalesCreditNote $action): RedirectResponse
    {
        $note = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credit note recorded.')]);

        return to_route('credit-notes.show', $note);
    }

    public function show(SalesCreditNote $creditNote): Response
    {
        $creditNote->load(['customer:id,code,name', 'invoice:id,invoice_number', 'cashBankAccount', 'journalEntry.lines.account:id,code,name', 'reversalJournalEntry:id,journal_number', 'createdBy:id,name', 'reversedBy:id,name']);

        return Inertia::render('finance/credit-notes/Show', [
            'note' => $this->summarise($creditNote) + [
                'reason' => $creditNote->reason,
                'settlement' => $creditNote->settlement->value,
                'settlement_label' => $creditNote->settlement->label(),
                'paid_from' => $creditNote->cashBankAccount === null ? null : $creditNote->cashBankAccount->display_name.' · '.$creditNote->payment_method?->label(),
                'created_by' => $creditNote->createdBy?->name,
                'posted_at' => BusinessClock::display($creditNote->posted_at),
                'reversed_by' => $creditNote->reversedBy?->name,
                'reversed_at' => BusinessClock::display($creditNote->reversed_at),
                'reversal_journal_number' => $creditNote->reversalJournalEntry?->journal_number,
                'can_reverse' => $creditNote->status->canReverse(),
                'journal' => $creditNote->journalEntry === null ? null : [
                    'id' => $creditNote->journalEntry->id,
                    'journal_number' => $creditNote->journalEntry->journal_number,
                    'lines' => $creditNote->journalEntry->lines->map(fn ($line): array => [
                        'account_code' => $line->account?->code,
                        'account_name' => $line->account?->name,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                    ])->values(),
                ],
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function reverse(SalesCreditNote $creditNote, ReverseSalesCreditNote $action): RedirectResponse
    {
        $action->handle($creditNote, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => $creditNote->settlement === SalesCreditNoteSettlement::Refund
            ? __('Credit note reversed. The money back is undone.')
            : __('Credit note reversed. The invoice is owed the amount again.')]);

        return to_route('credit-notes.show', $creditNote);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(SalesCreditNote $note): array
    {
        return [
            'id' => $note->id,
            'credit_note_number' => $note->credit_note_number,
            'credit_date' => $note->credit_date->toDateString(),
            'customer' => $note->customer?->name,
            'invoice_id' => $note->sales_invoice_id,
            'invoice_number' => $note->invoice?->invoice_number,
            'kind_label' => $note->kind->label(),
            'amount' => (string) $note->amount,
            'status' => $note->status->value,
            'status_label' => $note->status->label(),
        ];
    }
}
