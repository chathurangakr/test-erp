<?php

namespace App\Actions\Cash;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\ExpenseVoucherStatus;
use App\Models\ExpenseVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted expense voucher.
 *
 * The reversal is an exact mirror of the original journal — the expense accounts are credited
 * and the cash or bank account debited, at the original amounts — so the two sum to nothing.
 * Neither the voucher nor its lines are ever deleted: both the payment and its undoing stay
 * visible, which is what makes the correction auditable.
 */
class ReverseExpenseVoucher
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(ExpenseVoucher $voucher, ?int $userId = null): ExpenseVoucher
    {
        return DB::transaction(function () use ($voucher, $userId): ExpenseVoucher {
            $locked = ExpenseVoucher::query()
                ->whereKey($voucher->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted expense voucher can be reversed. :reference is :status.', [
                        'reference' => $locked->voucher_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Expense voucher :reference has already been reversed.', [
                        'reference' => $locked->voucher_number,
                    ]),
                ]);
            }

            $journal = $locked->journalEntry()->first();

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'status' => __('Expense voucher :reference has no journal to reverse.', [
                        'reference' => $locked->voucher_number,
                    ]),
                ]);
            }

            $reversal = $this->reverseJournal->handle($journal, $userId);

            $locked->forceFill([
                'status' => ExpenseVoucherStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ])->save();

            return $locked->refresh()->load(['lines.expenseAccount', 'reversalJournalEntry.lines']);
        });
    }
}
