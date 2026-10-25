<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\AdvanceStatus;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceApplication;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\AdvanceBalances;
use App\Support\DecimalMath;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets part of a customer advance against one of the customer's invoices still owed — never more than
 * is left of the advance, nor more than the invoice still owes, and never dated before either. The
 * invoice counts it as received and its status follows. Corrected by
 * ReverseCustomerAdvanceApplication. See docs/ACCOUNTING_RULES.md §34.
 */
class ApplyCustomerAdvance
{
    public function __construct(
        private readonly RecordOperationalJournal $accounting,
        private readonly AdvanceBalances $balances,
        private readonly AccountsReceivableService $receivables,
        private readonly RefreshSalesInvoiceStatus $refreshInvoice,
    ) {}

    /**
     * @param  array{sales_invoice_id: int, amount: string, application_date: string}  $data
     */
    public function handle(CustomerAdvance $advance, array $data, ?int $userId = null): CustomerAdvanceApplication
    {
        return DB::transaction(function () use ($advance, $data, $userId): CustomerAdvanceApplication {
            $locked = CustomerAdvance::query()->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($data['sales_invoice_id']);
            $date = CarbonImmutable::parse($data['application_date'])->startOfDay();
            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));

            if ($locked->status !== AdvanceStatus::Posted) {
                throw ValidationException::withMessages(['status' => __('Advance :reference has been reversed.', ['reference' => $locked->advance_number])]);
            }

            if ($invoice->customer_id !== $locked->customer_id || ! $invoice->status->isOutstanding()) {
                throw ValidationException::withMessages(['sales_invoice_id' => __('Choose an invoice of this customer that is still owed.')]);
            }

            if ($date->lessThan(CarbonImmutable::parse($invoice->invoice_date)) || $date->lessThan(CarbonImmutable::parse($locked->advance_date))) {
                throw ValidationException::withMessages(['application_date' => __('Apply it on or after both the advance and the invoice dates.')]);
            }

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
            }

            $remaining = $this->balances->remaining($locked);
            $outstanding = $this->receivables->outstanding($invoice);

            if (DecimalMath::compare($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => __('Only :remaining is left of this advance.', ['remaining' => DisplayNumber::money($remaining)])]);
            }

            if (DecimalMath::compare($amount, $outstanding, 2) > 0) {
                throw ValidationException::withMessages(['amount' => __('Invoice :reference is owed only :outstanding.', ['reference' => $invoice->invoice_number, 'outstanding' => DisplayNumber::money($outstanding)])]);
            }

            $application = CustomerAdvanceApplication::create([
                'customer_advance_id' => $locked->id,
                'sales_invoice_id' => $invoice->id,
                'application_date' => $date->toDateString(),
                'amount' => $amount,
                'status' => AdvanceStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->accounting->forCustomerAdvanceApplication($application->load(['advance', 'invoice']), $userId);
            $application->update(['journal_entry_id' => $journal->id]);
            $this->refreshInvoice->handle($invoice);

            return $application->refresh();
        });
    }
}
