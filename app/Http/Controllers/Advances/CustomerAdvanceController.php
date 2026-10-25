<?php

namespace App\Http\Controllers\Advances;

use App\Actions\Receivables\ApplyCustomerAdvance;
use App\Actions\Receivables\RecordCustomerAdvance;
use App\Actions\Receivables\RefundCustomerAdvance;
use App\Actions\Receivables\ReverseCustomerAdvance;
use App\Actions\Receivables\ReverseCustomerAdvanceApplication;
use App\Actions\Receivables\ReverseCustomerAdvanceRefund;
use App\Enums\SalesInvoiceStatus;
use App\Http\Requests\Advances\ApplyAdvanceRequest;
use App\Http\Requests\Advances\RefundAdvanceRequest;
use App\Http\Requests\Advances\StoreAdvanceRequest;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceApplication;
use App\Models\CustomerAdvanceRefund;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Customer advances: money received from customers before their invoice, and
 * setting it against their invoices. See docs/ACCOUNTING_RULES.md §34.
 */
class CustomerAdvanceController extends AdvanceScreens
{
    public function index(): Response
    {
        return $this->renderIndex();
    }

    public function create(): Response
    {
        return $this->renderCreate();
    }

    public function store(StoreAdvanceRequest $request, RecordCustomerAdvance $action): RedirectResponse
    {
        $advance = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Advance recorded.')]);

        return to_route('customer-advances.show', $advance);
    }

    public function show(CustomerAdvance $customerAdvance): Response
    {
        return $this->renderShow($customerAdvance);
    }

    public function reverse(CustomerAdvance $customerAdvance, ReverseCustomerAdvance $action): RedirectResponse
    {
        $action->handle($customerAdvance, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Advance reversed.')]);

        return to_route('customer-advances.show', $customerAdvance);
    }

    public function apply(ApplyAdvanceRequest $request, CustomerAdvance $customerAdvance, ApplyCustomerAdvance $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($customerAdvance, [
            'sales_invoice_id' => $data['invoice_id'],
            'amount' => $data['amount'],
            'application_date' => $data['application_date'],
        ], auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Advance applied to the invoice.')]);

        return to_route('customer-advances.show', $customerAdvance);
    }

    public function reverseApplication(CustomerAdvanceApplication $customerAdvanceApplication, ReverseCustomerAdvanceApplication $action): RedirectResponse
    {
        $action->handle($customerAdvanceApplication, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application reversed. The invoice is owed the amount again.')]);

        return to_route('customer-advances.show', $customerAdvanceApplication->customer_advance_id);
    }

    public function refund(RefundAdvanceRequest $request, CustomerAdvance $customerAdvance, RefundCustomerAdvance $action): RedirectResponse
    {
        $action->handle($customerAdvance, $request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Money given back.')]);

        return to_route('customer-advances.show', $customerAdvance);
    }

    public function reverseRefund(CustomerAdvanceRefund $customerAdvanceRefund, ReverseCustomerAdvanceRefund $action): RedirectResponse
    {
        $action->handle($customerAdvanceRefund, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund reversed. The advance has the amount back.')]);

        return to_route('customer-advances.show', $customerAdvanceRefund->customer_advance_id);
    }

    protected function party(): string
    {
        return 'customer';
    }

    protected function model(): string
    {
        return CustomerAdvance::class;
    }

    protected function parties(): Collection
    {
        return Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    protected function openInvoices(int $partyId): Collection
    {
        $service = app(AccountsReceivableService::class);

        return SalesInvoice::query()
            ->where('customer_id', $partyId)
            ->whereIn('status', [SalesInvoiceStatus::Posted->value, SalesInvoiceStatus::PartiallyPaid->value])
            ->orderBy('invoice_date')
            ->get()
            ->map(fn (SalesInvoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'date' => $invoice->invoice_date->toDateString(),
                'outstanding' => $service->outstanding($invoice),
            ])
            ->filter(fn (array $invoice): bool => DecimalMath::isPositive($invoice['outstanding']));
    }
}
