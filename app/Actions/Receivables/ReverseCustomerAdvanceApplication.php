<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AdvanceStatus;
use App\Models\CustomerAdvanceApplication;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes an advance application: its journal is reversed at the exact amounts, the invoice is
 * owed the amount again and the advance has it back to apply. See docs/ACCOUNTING_RULES.md §34.
 */
class ReverseCustomerAdvanceApplication
{
    public function __construct(
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly RefreshSalesInvoiceStatus $refreshInvoice,
    ) {}

    public function handle(CustomerAdvanceApplication $application, ?int $userId = null): CustomerAdvanceApplication
    {
        return DB::transaction(function () use ($application, $userId): CustomerAdvanceApplication {
            $locked = CustomerAdvanceApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages(['status' => __('This advance application has already been reversed.')]);
            }

            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($locked->sales_invoice_id);
            $reversal = $this->reverseJournal->handle($locked->journalEntry()->firstOrFail(), $userId);
            $locked->update([
                'status' => AdvanceStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ]);
            $this->refreshInvoice->handle($invoice);

            return $locked->refresh();
        });
    }
}
