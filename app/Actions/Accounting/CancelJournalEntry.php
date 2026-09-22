<?php

namespace App\Actions\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a DRAFT journal entry.
 *
 * CANCELLED and REVERSED are different states, as everywhere else in this system: a
 * cancelled entry never reached the books, a reversed one did and was undone. The document
 * and its lines survive either way — nothing is deleted.
 */
class CancelJournalEntry
{
    public function handle(JournalEntry $entry, ?int $userId = null): JournalEntry
    {
        return DB::transaction(function () use ($entry): JournalEntry {
            $locked = JournalEntry::query()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft journal entry can be cancelled. :reference is :status. A posted entry must be reversed.', [
                        'reference' => $locked->journal_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->update(['status' => JournalEntryStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}
