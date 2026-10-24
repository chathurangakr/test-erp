<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Actions\Inventory\ReverseInventoryTransaction;
use App\Actions\Receivables\RefreshSalesInvoiceStatus;
use App\Enums\SalesReturnStatus;
use App\Models\SalesReturn;
use App\Support\RecordText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a return recorded by mistake: the goods leave stock again at the cost they came back
 * with, the journal is mirrored exactly, and the invoice it credited is owed again. The record
 * stays, marked REVERSED.
 */
class ReverseSalesReturn
{
    public function __construct(
        private readonly ReverseInventoryTransaction $reverseInventory,
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly RefreshSalesInvoiceStatus $refreshInvoice,
    ) {}

    public function handle(SalesReturn $return, ?int $userId = null): SalesReturn
    {
        return DB::transaction(function () use ($return, $userId): SalesReturn {
            $locked = SalesReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse() || $locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Sales return :reference has already been reversed.', ['reference' => $locked->return_number]),
                ]);
            }

            $stock = $locked->inventoryTransaction()->first();
            $stockReversal = $stock === null ? null : $this->reverseInventory->handle($stock, [
                'notes' => RecordText::of('Reversal of sales return :reference', ['reference' => $locked->return_number]),
                'posted_by' => $userId ?? auth()->id(),
            ]);

            $journal = $locked->journalEntry()->firstOrFail();
            $journalReversal = $this->reverseJournal->handle($journal, $userId);

            $locked->update([
                'status' => SalesReturnStatus::Reversed,
                'reversal_inventory_transaction_id' => $stockReversal?->id,
                'reversal_journal_entry_id' => $journalReversal->id,
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ]);

            if ($locked->creditInvoice !== null) {
                $this->refreshInvoice->handle($locked->creditInvoice);
            }

            return $locked->refresh();
        });
    }
}
