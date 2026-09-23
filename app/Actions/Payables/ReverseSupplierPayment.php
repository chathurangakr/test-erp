<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\SupplierPaymentStatus;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted supplier payment.
 *
 * The payment and its allocations are never deleted. Marking it REVERSED is what releases
 * them: the payable service counts only allocations of payments that are still posted, so
 * each settled invoice's outstanding balance comes back on its own, and the invoice statuses
 * are then recalculated from that.
 */
class ReverseSupplierPayment
{
    public function __construct(
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly RefreshSupplierInvoiceStatus $refreshStatus,
    ) {}

    public function handle(SupplierPayment $payment, ?int $userId = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $userId): SupplierPayment {
            $locked = SupplierPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted supplier payment can be reversed. :reference is :status.', [
                        'reference' => $locked->payment_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Supplier payment :reference has already been reversed.', [
                        'reference' => $locked->payment_number,
                    ]),
                ]);
            }

            $journal = $locked->journalEntry()->first();

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'status' => __('Supplier payment :reference has no journal to reverse.', [
                        'reference' => $locked->payment_number,
                    ]),
                ]);
            }

            $reversal = $this->reverseJournal->handle($journal, $userId);

            $invoices = $locked->allocations()->with('invoice')->get()
                ->map(fn ($allocation) => $allocation->invoice)
                ->filter();

            $locked->forceFill([
                'status' => SupplierPaymentStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ])->save();

            // The allocations stop counting the moment the payment is no longer posted, so
            // each invoice's status is recalculated from its restored balance.
            foreach ($invoices as $invoice) {
                $this->refreshStatus->handle($invoice->refresh());
            }

            return $locked->refresh()->load(['allocations.invoice', 'reversalJournalEntry']);
        });
    }
}
