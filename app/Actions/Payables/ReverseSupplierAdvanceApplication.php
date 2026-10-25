<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AdvanceStatus;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes an advance application: its journal is reversed at the exact amounts, the invoice is
 * owed the amount again and the advance has it back to apply. See docs/ACCOUNTING_RULES.md §34.
 */
class ReverseSupplierAdvanceApplication
{
    public function __construct(
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly RefreshSupplierInvoiceStatus $refreshInvoice,
    ) {}

    public function handle(SupplierAdvanceApplication $application, ?int $userId = null): SupplierAdvanceApplication
    {
        return DB::transaction(function () use ($application, $userId): SupplierAdvanceApplication {
            $locked = SupplierAdvanceApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages(['status' => __('This advance application has already been reversed.')]);
            }

            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($locked->supplier_invoice_id);
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
