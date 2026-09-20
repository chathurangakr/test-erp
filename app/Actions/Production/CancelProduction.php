<?php

namespace App\Actions\Production;

use App\Enums\ProductionStatus;
use App\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a draft production order.
 *
 * Only a DRAFT may be cancelled: nothing has been consumed or produced, so cancelling
 * creates no ledger activity at all. A POSTED order really did convert stock and must be
 * reversed instead. The record is kept, never deleted.
 */
class CancelProduction
{
    public function handle(ProductionOrder $order, ?int $userId = null): ProductionOrder
    {
        return DB::transaction(function () use ($order): ProductionOrder {
            $locked = ProductionOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => $locked->status === ProductionStatus::Posted
                        ? __('Production order :reference is posted and must be reversed, not cancelled.', [
                            'reference' => $locked->production_number,
                        ])
                        : __('A :status production order cannot be cancelled.', [
                            'status' => $locked->status->label(),
                        ]),
                ]);
            }

            $locked->update(['status' => ProductionStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}
