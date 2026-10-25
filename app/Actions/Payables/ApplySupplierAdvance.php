<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\AdvanceStatus;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierInvoice;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AdvanceBalances;
use App\Support\DecimalMath;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets part of a supplier advance against one of the supplier's invoices still owed — never more than
 * is left of the advance, nor more than the invoice still owes, and never dated before either. The
 * invoice counts it as paid and its status follows. Corrected by
 * ReverseSupplierAdvanceApplication. See docs/ACCOUNTING_RULES.md §34.
 */
class ApplySupplierAdvance
{
    public function __construct(
        private readonly RecordOperationalJournal $accounting,
        private readonly AdvanceBalances $balances,
        private readonly AccountsPayableService $payables,
        private readonly RefreshSupplierInvoiceStatus $refreshInvoice,
    ) {}

    /**
     * @param  array{supplier_invoice_id: int, amount: string, application_date: string}  $data
     */
    public function handle(SupplierAdvance $advance, array $data, ?int $userId = null): SupplierAdvanceApplication
    {
        return DB::transaction(function () use ($advance, $data, $userId): SupplierAdvanceApplication {
            $locked = SupplierAdvance::query()->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($data['supplier_invoice_id']);
            $date = CarbonImmutable::parse($data['application_date'])->startOfDay();
            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));

            if ($locked->status !== AdvanceStatus::Posted) {
                throw ValidationException::withMessages(['status' => __('Advance :reference has been reversed.', ['reference' => $locked->advance_number])]);
            }

            if ($invoice->supplier_id !== $locked->supplier_id || ! $invoice->status->isOutstanding()) {
                throw ValidationException::withMessages(['supplier_invoice_id' => __('Choose an invoice of this supplier that is still owed.')]);
            }

            if ($date->lessThan(CarbonImmutable::parse($invoice->invoice_date)) || $date->lessThan(CarbonImmutable::parse($locked->advance_date))) {
                throw ValidationException::withMessages(['application_date' => __('Apply it on or after both the advance and the invoice dates.')]);
            }

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
            }

            $remaining = $this->balances->remaining($locked);
            $outstanding = $this->payables->outstanding($invoice);

            if (DecimalMath::compare($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => __('Only :remaining is left of this advance.', ['remaining' => DisplayNumber::money($remaining)])]);
            }

            if (DecimalMath::compare($amount, $outstanding, 2) > 0) {
                throw ValidationException::withMessages(['amount' => __('Invoice :reference is owed only :outstanding.', ['reference' => $invoice->internal_invoice_number, 'outstanding' => DisplayNumber::money($outstanding)])]);
            }

            $application = SupplierAdvanceApplication::create([
                'supplier_advance_id' => $locked->id,
                'supplier_invoice_id' => $invoice->id,
                'application_date' => $date->toDateString(),
                'amount' => $amount,
                'status' => AdvanceStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->accounting->forSupplierAdvanceApplication($application->load(['advance', 'invoice']), $userId);
            $application->update(['journal_entry_id' => $journal->id]);
            $this->refreshInvoice->handle($invoice);

            return $application->refresh();
        });
    }
}
