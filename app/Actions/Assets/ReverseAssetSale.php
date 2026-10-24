<?php

namespace App\Actions\Assets;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\FixedAssetStatus;
use App\Models\FixedAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a sale recorded by mistake: the sale journal is mirrored exactly (both stay in the
 * books, traceable to the asset) and the asset is back in use, ready to be sold again.
 */
class ReverseAssetSale
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(FixedAsset $asset, ?int $userId = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $userId): FixedAsset {
            $locked = FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== FixedAssetStatus::Sold) {
                throw ValidationException::withMessages(['status' => __('This asset has not been sold.')]);
            }

            $this->reverseJournal->handle($locked->saleJournalEntry()->firstOrFail(), $userId);

            $locked->update([
                'status' => FixedAssetStatus::InUse,
                'sold_date' => null,
                'sale_price' => null,
                'buyer' => null,
                'sale_cash_bank_account_id' => null,
                'sale_payment_method' => null,
                'sale_journal_entry_id' => null,
            ]);

            return $locked->refresh();
        });
    }
}
