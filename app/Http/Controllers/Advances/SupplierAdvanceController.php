<?php

namespace App\Http\Controllers\Advances;

use App\Actions\Payables\ApplySupplierAdvance;
use App\Actions\Payables\RecordSupplierAdvance;
use App\Actions\Payables\ReverseSupplierAdvance;
use App\Actions\Payables\ReverseSupplierAdvanceApplication;
use App\Enums\SupplierInvoiceStatus;
use App\Http\Requests\Advances\ApplyAdvanceRequest;
use App\Http\Requests\Advances\StoreAdvanceRequest;
use App\Models\Supplier;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierInvoice;
use App\Services\Accounting\AccountsPayableService;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Supplier advances: money paid to suppliers before their invoice, and
 * setting it against their invoices. See docs/ACCOUNTING_RULES.md §34.
 */
class SupplierAdvanceController extends AdvanceScreens
{
    public function index(): Response
    {
        return $this->renderIndex();
    }

    public function create(): Response
    {
        return $this->renderCreate();
    }

    public function store(StoreAdvanceRequest $request, RecordSupplierAdvance $action): RedirectResponse
    {
        $advance = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Advance recorded.')]);

        return to_route('supplier-advances.show', $advance);
    }

    public function show(SupplierAdvance $supplierAdvance): Response
    {
        return $this->renderShow($supplierAdvance);
    }

    public function reverse(SupplierAdvance $supplierAdvance, ReverseSupplierAdvance $action): RedirectResponse
    {
        $action->handle($supplierAdvance, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Advance reversed.')]);

        return to_route('supplier-advances.show', $supplierAdvance);
    }

    public function apply(ApplyAdvanceRequest $request, SupplierAdvance $supplierAdvance, ApplySupplierAdvance $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($supplierAdvance, [
            'supplier_invoice_id' => $data['invoice_id'],
            'amount' => $data['amount'],
            'application_date' => $data['application_date'],
        ], auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Advance applied to the invoice.')]);

        return to_route('supplier-advances.show', $supplierAdvance);
    }

    public function reverseApplication(SupplierAdvanceApplication $supplierAdvanceApplication, ReverseSupplierAdvanceApplication $action): RedirectResponse
    {
        $action->handle($supplierAdvanceApplication, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application reversed. The invoice is owed the amount again.')]);

        return to_route('supplier-advances.show', $supplierAdvanceApplication->supplier_advance_id);
    }

    protected function party(): string
    {
        return 'supplier';
    }

    protected function model(): string
    {
        return SupplierAdvance::class;
    }

    protected function parties(): Collection
    {
        return Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    protected function openInvoices(int $partyId): Collection
    {
        $service = app(AccountsPayableService::class);

        return SupplierInvoice::query()
            ->where('supplier_id', $partyId)
            ->whereIn('status', [SupplierInvoiceStatus::Posted->value, SupplierInvoiceStatus::PartiallyPaid->value])
            ->orderBy('invoice_date')
            ->get()
            ->map(fn (SupplierInvoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->internal_invoice_number,
                'date' => $invoice->invoice_date->toDateString(),
                'outstanding' => $service->outstanding($invoice),
            ])
            ->filter(fn (array $invoice): bool => DecimalMath::isPositive($invoice['outstanding']));
    }
}
