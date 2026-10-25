<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\SupplierInvoice;
use App\Services\Accounting\AdvanceBalances;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted supplier invoice.
 *
 * The invoice and its lines are never altered or deleted: the reversing journal cancels the
 * original exactly, and both stay on the record.
 *
 * **An invoice with live payments cannot be reversed.** Undoing the payable while money has
 * already been allocated against it would leave the payment settling something that no longer
 * exists. Reverse the payments first — the error says so.
 *
 * Reversal also **releases the GRNI it matched**: the reconciliation counts only invoices
 * that stand in the books, so the receipt's accrual becomes uninvoiced again automatically,
 * with nothing to unwind by hand.
 */
class ReverseSupplierInvoice
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(SupplierInvoice $invoice, ?int $userId = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): SupplierInvoice {
            $locked = SupplierInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted supplier invoice can be reversed. :reference is :status.', [
                        'reference' => $locked->internal_invoice_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Supplier invoice :reference has already been reversed.', [
                        'reference' => $locked->internal_invoice_number,
                    ]),
                ]);
            }

            $this->assertNotPaid($locked);

            $journal = $locked->journalEntry()->first();

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'status' => __('Supplier invoice :reference has no journal to reverse.', [
                        'reference' => $locked->internal_invoice_number,
                    ]),
                ]);
            }

            // Every line mirrored at its exact original amount.
            $reversal = $this->reverseJournal->handle($journal, $userId);

            $locked->forceFill([
                'status' => SupplierInvoiceStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ])->save();

            return $locked->refresh()->load(['lines', 'reversalJournalEntry']);
        });
    }

    /**
     * Payments that are still posted block the reversal.
     *
     * A reversed payment does not: it has already given the money back, so the invoice is
     * free to be undone.
     */
    private function assertNotPaid(SupplierInvoice $invoice): void
    {
        $paid = DecimalMath::cost((string) $invoice->allocations()
            ->whereHas('payment', fn ($query) => $query->where('status', SupplierPaymentStatus::Posted->value))
            ->sum('amount'));

        if (DecimalMath::isPositive($paid)) {
            throw ValidationException::withMessages([
                'status' => __('Supplier invoice :reference has :paid of payments allocated to it. Reverse the associated supplier payment(s) first.', [
                    'reference' => $invoice->internal_invoice_number,
                    'paid' => $paid,
                ]),
            ]);
        }

        if (DecimalMath::isPositive(app(AdvanceBalances::class)->appliedToSupplierInvoice($invoice))) {
            throw ValidationException::withMessages([
                'status' => __('Supplier invoice :reference has a supplier advance applied to it. Reverse that application first.', [
                    'reference' => $invoice->internal_invoice_number,
                ]),
            ]);
        }
    }
}
