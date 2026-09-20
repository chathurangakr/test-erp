<?php

namespace App\Actions\Sales;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a sales order.
 *
 * A cancelled order can never be delivered against. An order that has already been
 * partially delivered cannot be cancelled — the goods really left the warehouse, so the
 * correct remedy is to reverse the delivery first.
 */
class CancelSalesOrder
{
    public function handle(SalesOrder $order, ?int $userId = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId): SalesOrder {
            $locked = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('A :status sales order cannot be cancelled.', [
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->update([
                'status' => SalesOrderStatus::Cancelled,
                'cancelled_by' => $userId ?? auth()->id(),
                'cancelled_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
