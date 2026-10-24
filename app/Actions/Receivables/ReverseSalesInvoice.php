<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\CustomerReceiptStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesReturnStatus;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted sales invoice.
 *
 * This is an **audit correction**, not a credit note. A commercial return, allowance or
 * discount is a different document with its own accounting and its own paper trail, and is
 * deliberately not built — see docs/ACCOUNTING_RULES.md §19. Reversal exists to undo an
 * invoice that should not have been raised at all.
 *
 * **An invoice with live receipts cannot be reversed.** Undoing the receivable while money
 * has already been allocated against it would leave the receipt settling something that no
 * longer exists. Reverse the receipts first — the error says so.
 *
 * Reversal also **releases the delivery billing**: the reconciliation counts only invoices
 * standing in the books, so the delivered value becomes unbilled again automatically.
 */
class ReverseSalesInvoice
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(SalesInvoice $invoice, ?int $userId = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $locked = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted sales invoice can be reversed. :reference is :status.', [
                        'reference' => $locked->invoice_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Sales invoice :reference has already been reversed.', [
                        'reference' => $locked->invoice_number,
                    ]),
                ]);
            }

            $this->assertNotReceipted($locked);
            $this->assertNoReturns($locked);

            $journal = $locked->journalEntry()->first();

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'status' => __('Sales invoice :reference has no journal to reverse.', [
                        'reference' => $locked->invoice_number,
                    ]),
                ]);
            }

            // Every line mirrored at its exact original amount.
            $reversal = $this->reverseJournal->handle($journal, $userId);

            $locked->forceFill([
                'status' => SalesInvoiceStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ])->save();

            return $locked->refresh()->load(['lines', 'reversalJournalEntry']);
        });
    }

    /**
     * Receipts that are still posted block the reversal.
     *
     * A reversed receipt does not: the money has already been given back, so the invoice is
     * free to be undone.
     */
    private function assertNotReceipted(SalesInvoice $invoice): void
    {
        $received = DecimalMath::cost((string) $invoice->allocations()
            ->whereHas('receipt', fn ($query) => $query->where('status', CustomerReceiptStatus::Posted->value))
            ->sum('amount'));

        if (DecimalMath::isPositive($received)) {
            throw ValidationException::withMessages([
                'status' => __('Sales invoice :reference has :received of receipts allocated to it. Reverse the associated customer receipt(s) first.', [
                    'reference' => $invoice->invoice_number,
                    'received' => $received,
                ]),
            ]);
        }
    }

    /**
     * A standing sales return blocks the reversal — one crediting this invoice, or one against
     * goods it billed. Undoing the invoice would leave the return crediting what no longer
     * exists. Reverse the return first.
     */
    private function assertNoReturns(SalesInvoice $invoice): void
    {
        $return = SalesReturn::query()
            ->where('status', SalesReturnStatus::Posted->value)
            ->where(fn ($query) => $query->where('credit_invoice_id', $invoice->getKey())->orWhere('original_invoice_id', $invoice->getKey()))
            ->first();

        if ($return !== null) {
            throw ValidationException::withMessages([
                'status' => __('Sales invoice :reference is linked to sales return :return. Reverse the return first.', [
                    'reference' => $invoice->invoice_number,
                    'return' => $return->return_number,
                ]),
            ]);
        }
    }
}
