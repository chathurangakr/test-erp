<?php

namespace App\Actions\Cash;

use App\Enums\ExpenseVoucherStatus;
use App\Models\ExpenseVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a DRAFT expense voucher.
 *
 * Cancelling and reversing are different facts and must never be confused. A cancelled
 * voucher never reached the books — it was abandoned before posting — so there is nothing to
 * undo. A posted one must be reversed instead, which leaves an opposite journal behind.
 *
 * The voucher is kept, never deleted: an abandoned document is still part of the record.
 */
class CancelExpenseVoucher
{
    public function handle(ExpenseVoucher $voucher, ?int $userId = null): ExpenseVoucher
    {
        return DB::transaction(function () use ($voucher): ExpenseVoucher {
            $locked = ExpenseVoucher::query()
                ->whereKey($voucher->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft expense voucher can be cancelled. :reference is :status — a posted voucher must be reversed.', [
                        'reference' => $locked->voucher_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->forceFill(['status' => ExpenseVoucherStatus::Cancelled])->save();

            return $locked->refresh();
        });
    }
}
