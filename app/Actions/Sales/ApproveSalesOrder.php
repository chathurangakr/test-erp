<?php

namespace App\Actions\Sales;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a draft sales order, turning it into a controlled document.
 *
 * After approval the commercial values — quantities and prices — are preserved and may no
 * longer be freely edited. See docs/BUSINESS_RULES.md §15.
 */
class ApproveSalesOrder
{
    public function handle(SalesOrder $order, ?int $userId = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId): SalesOrder {
            $locked = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canApprove()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft sales order can be approved. :reference is :status.', [
                        'reference' => $locked->so_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->lines()->count() === 0) {
                throw ValidationException::withMessages([
                    'lines' => __('A sales order must have at least one line before it can be approved.'),
                ]);
            }

            $locked->update([
                'status' => SalesOrderStatus::Approved,
                'approved_by' => $userId ?? auth()->id(),
                'approved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
