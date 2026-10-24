<?php

namespace App\Actions\Loans;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\LoanDocumentStatus;
use App\Models\LoanPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a loan payment recorded by mistake, with an exact mirror journal: the money is back in
 * the account and the loan owes that principal again.
 */
class ReverseLoanPayment
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(LoanPayment $payment, ?int $userId = null): LoanPayment
    {
        return DB::transaction(function () use ($payment, $userId): LoanPayment {
            $locked = LoanPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Loan payment :reference has already been reversed.', ['reference' => $locked->payment_number]),
                ]);
            }

            $reversal = $this->reverseJournal->handle($locked->journalEntry()->firstOrFail(), $userId);

            $locked->update([
                'status' => LoanDocumentStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
