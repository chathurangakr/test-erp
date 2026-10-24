<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\SalesCreditNoteStatus;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a credit note: its journal is reversed at the exact amounts (dated today, in the open
 * period containing it) and the invoice is owed the amount again. See docs/ACCOUNTING_RULES.md §33.
 */
class ReverseSalesCreditNote
{
    public function __construct(
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly RefreshSalesInvoiceStatus $refreshInvoice,
    ) {}

    public function handle(SalesCreditNote $note, ?int $userId = null): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $userId): SalesCreditNote {
            $locked = SalesCreditNote::query()->whereKey($note->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages(['status' => __('Credit note :reference has already been reversed.', ['reference' => $locked->credit_note_number])]);
            }

            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($locked->sales_invoice_id);
            $reversal = $this->reverseJournal->handle($locked->journalEntry()->firstOrFail(), $userId);

            $locked->update([
                'status' => SalesCreditNoteStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ]);
            $this->refreshInvoice->handle($invoice);

            return $locked->refresh();
        });
    }
}
