<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\RecordSalesReturn;
use App\Actions\Sales\ReverseSalesReturn;
use App\Enums\DeliveryStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesReturnSettlement;
use App\Enums\SalesReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Requests\Payroll\PayPayrollRequest;
use App\Http\Requests\Sales\StoreSalesReturnRequest;
use App\Models\Customer;
use App\Models\DeliveryLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\CashDocumentOptions;
use App\Services\Accounting\SalesBillingReconciliationService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods customers brought back: credited at the price charged, good goods back in stock,
 * damaged ones a loss, settled by reducing an invoice, an exchange or money back. Recorded in
 * one step and corrected by reversal. See docs/ACCOUNTING_RULES.md §32.
 */
class SalesReturnController extends Controller
{
    private const PER_PAGE = 25;

    /** How far back the form looks for deliveries to return against. */
    private const LOOKBACK_DAYS = 180;

    public function __construct(
        private readonly AccountsReceivableService $receivables,
        private readonly SalesBillingReconciliationService $billing,
    ) {}

    public function index(Request $request): Response
    {
        $customerId = $request->integer('customer_id') ?: null;

        $returns = SalesReturn::query()
            ->with(['customer:id,code,name', 'product:id,code,name', 'deliveryLine.delivery:id,delivery_number'])
            ->when($customerId, fn ($query, int $id) => $query->where('customer_id', $id))
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SalesReturn $return): array => $this->summarise($return));

        return Inertia::render('sales/returns/Index', [
            'returns' => $returns,
            'filters' => ['customer_id' => $customerId],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(Request $request, CashDocumentOptions $options): Response
    {
        $since = BusinessClock::today()->subDays(self::LOOKBACK_DAYS)->toDateString();

        $lines = DeliveryLine::query()
            ->with(['delivery.customer:id,code,name', 'product.primaryUom:id,code', 'product.secondaryUom:id,code'])
            ->whereNull('backflush_product_id')
            ->whereHas('delivery', fn ($query) => $query->where('status', DeliveryStatus::Posted->value)->whereDate('delivery_date', '>=', $since))
            ->whereHas('invoiceLines.invoice', fn ($query) => $query->whereIn('status', $this->liveStatuses()))
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (DeliveryLine $line): ?array => $this->returnableLine($line))
            ->filter()
            ->values();

        return Inertia::render('sales/returns/Create', [
            'lines' => $lines,
            'deliveryLineId' => $request->integer('delivery_line_id') ?: null,
            // Invoices still owed, which a credit or an exchange can reduce.
            'invoices' => SalesInvoice::query()
                ->whereIn('status', $this->liveStatuses())
                ->whereIn('customer_id', $lines->pluck('customer_id')->unique())
                ->orderByDesc('invoice_date')
                ->get(['id', 'invoice_number', 'customer_id', 'invoice_date', 'status'])
                ->map(fn (SalesInvoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_id' => $invoice->customer_id,
                    'invoice_date' => $invoice->invoice_date->toDateString(),
                    'outstanding' => $this->receivables->outstanding($invoice),
                ])
                ->filter(fn (array $invoice): bool => DecimalMath::isPositive($invoice['outstanding']))
                ->values(),
            'settlements' => SalesReturnSettlement::options(),
            'warehouses' => Warehouse::query()->operational()->orderByDesc('is_default')->orderBy('code')->get(['id', 'code', 'name', 'is_default']),
            'accounts' => $options->cashBankAccounts(),
            'paymentMethods' => PayPayrollRequest::paymentMethodOptions(),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreSalesReturnRequest $request, RecordSalesReturn $action): RedirectResponse
    {
        $return = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Return recorded.')]);

        return to_route('sales-returns.show', $return);
    }

    public function show(SalesReturn $salesReturn): Response
    {
        $salesReturn->load([
            'customer:id,code,name',
            'product:id,code,name,primary_uom_id,secondary_uom_id',
            'product.primaryUom:id,code',
            'product.secondaryUom:id,code',
            'deliveryLine.delivery:id,delivery_number',
            'warehouse:id,code,name',
            'lot:id,lot_number',
            'originalInvoice:id,invoice_number',
            'creditInvoice:id,invoice_number',
            'cashBankAccount:id,display_name',
            'inventoryTransaction:id,reference',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'reversedBy:id,name',
        ]);

        return Inertia::render('sales/returns/Show', [
            'salesReturn' => $this->summarise($salesReturn) + [
                'delivery_id' => $salesReturn->deliveryLine?->delivery_id,
                'uom' => $salesReturn->product?->primaryUom?->code,
                'secondary_uom' => $salesReturn->product?->secondaryUom?->code,
                'good_quantity' => (string) $salesReturn->good_quantity,
                'damaged_quantity' => (string) $salesReturn->damaged_quantity,
                'secondary_quantity' => $salesReturn->secondary_quantity === null ? null : (string) $salesReturn->secondary_quantity,
                'unit_price' => (string) $salesReturn->unit_price,
                'good_cost' => (string) $salesReturn->good_cost,
                'damaged_cost' => (string) $salesReturn->damaged_cost,
                'warehouse' => $salesReturn->warehouse ? "{$salesReturn->warehouse->code} — {$salesReturn->warehouse->name}" : null,
                'lot_number' => $salesReturn->lot?->lot_number,
                'original_invoice' => $salesReturn->originalInvoice?->only(['id', 'invoice_number']),
                'credit_invoice' => $salesReturn->creditInvoice?->only(['id', 'invoice_number']),
                'payment_account' => $salesReturn->cashBankAccount?->display_name,
                'payment_method_label' => $salesReturn->payment_method?->label(),
                'reference' => $salesReturn->reference,
                'inventory_reference' => $salesReturn->inventoryTransaction?->reference,
                'created_by' => $salesReturn->createdBy?->name,
                'posted_at' => BusinessClock::display($salesReturn->posted_at),
                'reversed_by' => $salesReturn->reversedBy?->name,
                'reversed_at' => BusinessClock::display($salesReturn->reversed_at),
                'reversal_journal_number' => $salesReturn->reversalJournalEntry?->journal_number,
                'can_reverse' => $salesReturn->status->canReverse(),
                'journal' => PayrollController::journal($salesReturn->journalEntry),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function reverse(SalesReturn $salesReturn, ReverseSalesReturn $action): RedirectResponse
    {
        $action->handle($salesReturn, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Return reversed.')]);

        return to_route('sales-returns.show', $salesReturn);
    }

    /**
     * A delivery line with something left to return, and what the form needs to price it.
     *
     * @return array<string, mixed>|null
     */
    private function returnableLine(DeliveryLine $line): ?array
    {
        $returned = SalesReturn::query()
            ->where('delivery_line_id', $line->id)
            ->where('status', SalesReturnStatus::Posted->value)
            ->get(['good_quantity', 'damaged_quantity', 'credit_amount']);
        $left = DecimalMath::quantity(DecimalMath::sub(
            DecimalMath::of((string) $line->quantity),
            $returned->reduce(fn (string $sum, SalesReturn $r): string => DecimalMath::add($sum, $r->totalQuantity()), DecimalMath::zero()),
        ));

        if (! DecimalMath::isPositive($left)) {
            return null;
        }

        $invoice = SalesInvoiceLine::query()
            ->with('invoice:id,invoice_number,invoice_date,status')
            ->where('delivery_line_id', $line->id)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', $this->liveStatuses()))
            ->orderBy('id')
            ->first()?->invoice;

        return [
            'id' => $line->id,
            'delivery_id' => $line->delivery_id,
            'delivery_number' => $line->delivery->delivery_number,
            'delivery_date' => $line->delivery->delivery_date->toDateString(),
            'warehouse_id' => $line->delivery->warehouse_id,
            'customer_id' => $line->delivery->customer_id,
            'customer_name' => $line->delivery->customer?->name,
            'customer_code' => $line->delivery->customer?->code,
            'product_code' => $line->product->code,
            'product_name' => $line->product->name,
            'uom' => $line->product->primaryUom?->code,
            'secondary_uom' => $line->product->secondaryUom?->code,
            'priced_by_weight' => $line->pricing_basis->isSecondary(),
            'quantity' => (string) $line->quantity,
            'returnable' => $left,
            'unit_price' => (string) $line->unit_price,
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'received' => $this->receivables->receivedAmount($invoice),
                'outstanding' => $this->receivables->outstanding($invoice),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function liveStatuses(): array
    {
        return [SalesInvoiceStatus::Posted->value, SalesInvoiceStatus::PartiallyPaid->value, SalesInvoiceStatus::Paid->value];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(SalesReturn $return): array
    {
        return [
            'id' => $return->id,
            'return_number' => $return->return_number,
            'return_date' => $return->return_date->toDateString(),
            'customer_name' => $return->customer?->name,
            'customer_code' => $return->customer?->code,
            'product_name' => $return->product?->name,
            'delivery_number' => $return->deliveryLine?->delivery?->delivery_number,
            'quantity' => $return->totalQuantity(),
            'credit_amount' => (string) $return->credit_amount,
            'settlement' => $return->settlement->value,
            'settlement_label' => $return->settlement->label(),
            'reason' => $return->reason,
            'status' => $return->status->value,
            'status_label' => $return->status->label(),
            'is_reversed' => $return->status === SalesReturnStatus::Reversed,
        ];
    }
}
