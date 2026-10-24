<?php

namespace App\Actions\Assets;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\FixedAssetStatus;
use App\Enums\LoanDocumentStatus;
use App\Models\FixedAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes an asset recorded by mistake, with an exact mirror journal — and the loan it opened,
 * which shares that journal. Refused once it is sold, while maintenance on it still stands, or
 * while a payment on its loan stands: undo those first.
 */
class ReverseFixedAsset
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(FixedAsset $asset, ?int $userId = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $userId): FixedAsset {
            $locked = FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== FixedAssetStatus::InUse) {
                throw ValidationException::withMessages([
                    'status' => $locked->status === FixedAssetStatus::Sold
                        ? __('Asset :reference is sold. Reverse the sale first.', ['reference' => $locked->asset_number])
                        : __('Asset :reference has already been reversed.', ['reference' => $locked->asset_number]),
                ]);
            }

            if ($locked->maintenances()->where('status', LoanDocumentStatus::Posted->value)->exists()) {
                throw ValidationException::withMessages([
                    'status' => __('Asset :reference has maintenance recorded. Reverse that first.', ['reference' => $locked->asset_number]),
                ]);
            }

            $loan = $locked->loan()->lockForUpdate()->first();

            if ($loan !== null && $loan->payments()->where('status', LoanDocumentStatus::Posted->value)->exists()) {
                throw ValidationException::withMessages([
                    'status' => __('The loan for this asset has payments recorded. Reverse those payments first.'),
                ]);
            }

            $reversal = $this->reverseJournal->handle($locked->journalEntry()->firstOrFail(), $userId);
            $audit = ['reversal_journal_entry_id' => $reversal->id, 'reversed_by' => $userId, 'reversed_at' => now()];

            $locked->update(['status' => FixedAssetStatus::Reversed, ...$audit]);
            $loan?->update(['status' => LoanDocumentStatus::Reversed, ...$audit]);

            return $locked->refresh();
        });
    }
}
