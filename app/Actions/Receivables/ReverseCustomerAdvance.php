<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AdvanceStatus;
use App\Models\CustomerAdvance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a customer advance recorded by mistake: its journal is reversed at the exact amounts. Refused
 * while any of it stands applied to an invoice — reverse those applications first.
 * See docs/ACCOUNTING_RULES.md §34.
 */
class ReverseCustomerAdvance
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(CustomerAdvance $advance, ?int $userId = null): CustomerAdvance
    {
        return DB::transaction(function () use ($advance, $userId): CustomerAdvance {
            $locked = CustomerAdvance::query()->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages(['status' => __('Advance :reference has already been reversed.', ['reference' => $locked->advance_number])]);
            }

            if ($locked->applications()->where('status', AdvanceStatus::Posted->value)->exists()) {
                throw ValidationException::withMessages(['status' => __('Advance :reference is applied to invoices. Reverse those applications first.', ['reference' => $locked->advance_number])]);
            }

            if ($locked->refunds()->where('status', AdvanceStatus::Posted->value)->exists()) {
                throw ValidationException::withMessages(['status' => __('Advance :reference has a refund recorded against it. Reverse that refund first.', ['reference' => $locked->advance_number])]);
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
