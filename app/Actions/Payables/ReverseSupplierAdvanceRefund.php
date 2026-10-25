<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AdvanceStatus;
use App\Models\SupplierAdvanceRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes an advance refund: its journal is reversed at the exact amounts and the advance has the
 * amount back. See docs/ACCOUNTING_RULES.md §34.
 */
class ReverseSupplierAdvanceRefund
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(SupplierAdvanceRefund $refund, ?int $userId = null): SupplierAdvanceRefund
    {
        return DB::transaction(function () use ($refund, $userId): SupplierAdvanceRefund {
            $locked = SupplierAdvanceRefund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

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
