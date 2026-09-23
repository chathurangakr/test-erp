<?php

namespace App\Actions\Receivables;

use App\Enums\CustomerReceiptStatus;
use App\Models\CustomerReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a DRAFT customer receipt — one that never reached the books.
 */
class CancelCustomerReceipt
{
    public function handle(CustomerReceipt $receipt, ?int $userId = null): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt): CustomerReceipt {
            $locked = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft customer receipt can be cancelled. :reference is :status. A posted receipt must be reversed.', [
                        'reference' => $locked->receipt_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->update(['status' => CustomerReceiptStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}
