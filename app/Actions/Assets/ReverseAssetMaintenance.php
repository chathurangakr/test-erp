<?php

namespace App\Actions\Assets;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\LoanDocumentStatus;
use App\Models\AssetMaintenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a maintenance record entered by mistake, mirroring its journal if it had one.
 */
class ReverseAssetMaintenance
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(AssetMaintenance $maintenance, ?int $userId = null): AssetMaintenance
    {
        return DB::transaction(function () use ($maintenance, $userId): AssetMaintenance {
            $locked = AssetMaintenance::query()->whereKey($maintenance->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Maintenance :reference has already been reversed.', ['reference' => $locked->maintenance_number]),
                ]);
            }

            $journal = $locked->journalEntry()->first();
            $reversal = $journal === null ? null : $this->reverseJournal->handle($journal, $userId);

            $locked->update([
                'status' => LoanDocumentStatus::Reversed,
                'reversal_journal_entry_id' => $reversal?->id,
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
