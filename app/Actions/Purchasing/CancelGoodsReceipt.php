<?php

namespace App\Actions\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a draft goods receipt.
 *
 * Only a DRAFT may be cancelled, because a draft has never touched inventory — cancelling
 * it therefore creates no stock and needs no ledger activity at all. A POSTED receipt has
 * real stock behind it and must be **reversed** instead, which writes an opposite
 * transaction; see ReverseGoodsReceipt.
 *
 * The record is kept, never deleted: purchasing history is permanent.
 */
class CancelGoodsReceipt
{
    public function handle(GoodsReceipt $receipt, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $locked = GoodsReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => $locked->status === GoodsReceiptStatus::Posted
                        ? __('Goods receipt :reference is posted and must be reversed, not cancelled.', [
                            'reference' => $locked->grn_number,
                        ])
                        : __('A :status goods receipt cannot be cancelled.', [
                            'status' => $locked->status->label(),
                        ]),
                ]);
            }

            $locked->update(['status' => GoodsReceiptStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}
