<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a draft purchase order, turning it into a controlled document.
 *
 * After approval the header and lines may no longer be freely edited — see
 * docs/BUSINESS_RULES.md §11.
 */
class ApprovePurchaseOrder
{
    public function handle(PurchaseOrder $order, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canApprove()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft purchase order can be approved. :reference is :status.', [
                        'reference' => $locked->po_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->lines()->count() === 0) {
                throw ValidationException::withMessages([
                    'lines' => __('A purchase order must have at least one line before it can be approved.'),
                ]);
            }

            $locked->update([
                'status' => PurchaseOrderStatus::Approved,
                'approved_by' => $userId ?? auth()->id(),
                'approved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
