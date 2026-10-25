<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AdvanceStatus;
use App\Models\CustomerAdvanceRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes an advance refund: its journal is reversed at the exact amounts and the advance has the
 * amount back. See docs/ACCOUNTING_RULES.md §34.
 */
class ReverseCustomerAdvanceRefund
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(CustomerAdvanceRefund $refund, ?int $userId = null): CustomerAdvanceRefund
    {
        return DB::transaction(function () use ($refund, $userId): CustomerAdvanceRefund {
            $locked = CustomerAdvanceRefund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages(['status' => __('This advance refund has already been reversed.')]);
            }

            $reversal = $this->reverseJournal->handle($locked->journalEntry()->firstOrFail(), $userId);
            $locked->update([
                'status' => AdvanceStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_by' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
