<?php

namespace App\Actions\Payables;

use App\Enums\SupplierPaymentStatus;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a DRAFT supplier payment — one that never reached the books.
 */
class CancelSupplierPayment
{
    public function handle(SupplierPayment $payment, ?int $userId = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment): SupplierPayment {
            $locked = SupplierPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft supplier payment can be cancelled. :reference is :status. A posted payment must be reversed.', [
                        'reference' => $locked->payment_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->update(['status' => SupplierPaymentStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}
