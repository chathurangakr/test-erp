<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AdvanceStatus;
use App\Models\SupplierAdvance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a supplier advance recorded by mistake: its journal is reversed at the exact amounts. Refused
 * while any of it stands applied to an invoice — reverse those applications first.
 * See docs/ACCOUNTING_RULES.md §34.
 */
class ReverseSupplierAdvance
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(SupplierAdvance $advance, ?int $userId = null): SupplierAdvance
    {
        return DB::transaction(function () use ($advance, $userId): SupplierAdvance {
            $locked = SupplierAdvance::query()->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages(['status' => __('Advance :reference has already been reversed.', ['reference' => $locked->advance_number])]);
            }

            if ($locked->applications()->where('status', AdvanceStatus::Posted->value)->exists()) {
                throw ValidationException::withMessages(['status' => __('Advance :reference is applied to invoices. Reverse those applications first.', ['reference' => $locked->advance_number])]);
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
