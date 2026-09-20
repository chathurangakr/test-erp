<?php

namespace App\Actions\Sales;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a draft delivery.
 *
 * Only a DRAFT may be cancelled: it has never touched inventory, so cancelling it creates
 * no ledger activity at all. A POSTED delivery has really shipped goods and must be
 * reversed instead. The record is kept, never deleted.
 */
class CancelDelivery
{
    public function handle(Delivery $delivery, ?int $userId = null): Delivery
    {
        return DB::transaction(function () use ($delivery): Delivery {
            $locked = Delivery::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => $locked->status === DeliveryStatus::Posted
                        ? __('Delivery :reference is posted and must be reversed, not cancelled.', [
                            'reference' => $locked->delivery_number,
                        ])
                        : __('A :status delivery cannot be cancelled.', [
                            'status' => $locked->status->label(),
                        ]),
                ]);
            }

            $locked->update(['status' => DeliveryStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}
