<?php

namespace App\Actions\Cash;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\FundTransferStatus;
use App\Models\FundTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted fund transfer.
 *
 * The reversal is an exact mirror: the source account is debited and the destination credited,
 * at the original amount, so the money is back where it started and the two journals sum to
 * nothing. The transfer itself is never deleted — both the movement and its undoing stay
 * visible, which is what makes the correction auditable.
 */
class ReverseFundTransfer
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(FundTransfer $transfer, ?int $userId = null): FundTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): FundTransfer {
            $locked = FundTransfer::query()
                ->whereKey($transfer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted fund transfer can be reversed. :reference is :status.', [
                        'reference' => $locked->transfer_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Fund transfer :reference has already been reversed.', [
                        'reference' => $locked->transfer_number,
                    ]),
                ]);
            }

            $journal = $locked->journalEntry()->first();

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'status' => __('Fund transfer :reference has no journal to reverse.', [
                        'reference' => $locked->transfer_number,
                    ]),
                ]);
            }

            $reversal = $this->reverseJournal->handle($journal, $userId);

            $locked->forceFill([
                'status' => FundTransferStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ])->save();

            return $locked->refresh()->load(['fromAccount', 'toAccount', 'reversalJournalEntry.lines']);
        });
    }
}
