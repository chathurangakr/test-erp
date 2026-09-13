<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a purchase order.
 *
 * A cancelled order can never be received against. An order that has already received
 * stock cannot be cancelled — the received goods are real, so the correct remedy is to
 * reverse the goods receipt first.
 */
class CancelPurchaseOrder
{
    public function handle(PurchaseOrder $order, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('A :status purchase order cannot be cancelled.', [
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->update([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_by' => $userId ?? auth()->id(),
                'cancelled_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
