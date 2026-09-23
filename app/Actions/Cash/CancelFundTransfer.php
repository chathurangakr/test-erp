<?php

namespace App\Actions\Cash;

use App\Enums\FundTransferStatus;
use App\Models\FundTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a DRAFT fund transfer.
 *
 * Cancelling and reversing are different facts and must never be confused. A cancelled
 * transfer never reached the books — it was abandoned before posting — so there is nothing to
 * undo. A posted one must be reversed instead, which leaves an opposite journal behind.
 *
 * The transfer is kept, never deleted: an abandoned document is still part of the record.
 */
class CancelFundTransfer
{
    public function handle(FundTransfer $transfer, ?int $userId = null): FundTransfer
    {
        return DB::transaction(function () use ($transfer): FundTransfer {
            $locked = FundTransfer::query()
                ->whereKey($transfer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft fund transfer can be cancelled. :reference is :status — a posted transfer must be reversed.', [
                        'reference' => $locked->transfer_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->forceFill(['status' => FundTransferStatus::Cancelled])->save();

            return $locked->refresh();
        });
    }
}
