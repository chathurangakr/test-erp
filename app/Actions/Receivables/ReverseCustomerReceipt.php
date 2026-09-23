<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\CustomerReceiptStatus;
use App\Models\CustomerReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted customer receipt.
 *
 * The receipt and its allocations are never deleted. Marking it REVERSED is what releases
 * them: the receivable service counts only allocations of receipts that are still posted, so
 * each settled invoice's outstanding balance comes back on its own, and the invoice statuses
 * are then recalculated from that.
 */
class ReverseCustomerReceipt
{
    public function __construct(
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly RefreshSalesInvoiceStatus $refreshStatus,
    ) {}

    public function handle(CustomerReceipt $receipt, ?int $userId = null): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): CustomerReceipt {
            $locked = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted customer receipt can be reversed. :reference is :status.', [
                        'reference' => $locked->receipt_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Customer receipt :reference has already been reversed.', [
                        'reference' => $locked->receipt_number,
                    ]),
                ]);
            }

            $journal = $locked->journalEntry()->first();

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'status' => __('Customer receipt :reference has no journal to reverse.', [
                        'reference' => $locked->receipt_number,
                    ]),
                ]);
            }

            $reversal = $this->reverseJournal->handle($journal, $userId);

            $invoices = $locked->allocations()->with('invoice')->get()
                ->map(fn ($allocation) => $allocation->invoice)
                ->filter();

            $locked->forceFill([
                'status' => CustomerReceiptStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ])->save();

            // The allocations stop counting the moment the receipt is no longer posted, so
            // each invoice's status is recalculated from its restored balance.
            foreach ($invoices as $invoice) {
                $this->refreshStatus->handle($invoice->refresh());
            }

            return $locked->refresh()->load(['allocations.invoice', 'reversalJournalEntry']);
        });
    }
}
