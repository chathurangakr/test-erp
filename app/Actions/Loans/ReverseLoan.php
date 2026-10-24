<?php

namespace App\Actions\Loans;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\LoanDocumentStatus;
use App\Enums\LoanFunding;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a loan recorded by mistake, with an exact mirror journal. Refused while a payment on
 * it still stands — reverse the payments first.
 */
class ReverseLoan
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(Loan $loan, ?int $userId = null): Loan
    {
        return DB::transaction(function () use ($loan, $userId): Loan {
            $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Loan :reference has already been reversed.', ['reference' => $locked->loan_number]),
                ]);
            }

            if ($locked->funding === LoanFunding::Asset) {
                throw ValidationException::withMessages([
                    'status' => __('Loan :reference was opened with an asset. Reverse the asset instead.', ['reference' => $locked->loan_number]),
                ]);
            }

            if ($locked->payments()->where('status', LoanDocumentStatus::Posted->value)->exists()) {
                throw ValidationException::withMessages([
                    'status' => __('Loan :reference has payments recorded. Reverse those payments first.', ['reference' => $locked->loan_number]),
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
