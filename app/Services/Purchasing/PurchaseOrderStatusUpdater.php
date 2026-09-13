<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Recomputes a purchase order's status from its lines' received quantities.
 *
 * Called after a goods receipt is posted or reversed. The status is always derived from the
 * line quantities rather than incremented, so posting and reversal cannot leave it stale.
 */
class PurchaseOrderStatusUpdater
{
    /**
     * Apply a goods receipt's quantities to the linked purchase order lines, then recompute
     * the order status. Posting adds; reversal subtracts.
     *
     * @param  Collection<int, GoodsReceiptLine>  $lines
     */
    public function applyReceipt(GoodsReceipt $receipt, Collection $lines, bool $reverse): void
    {
        if ($receipt->purchase_order_id === null) {
            return;
        }

        foreach ($lines as $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }

            $orderLine = PurchaseOrderLine::query()
                ->whereKey($line->purchase_order_line_id)
                ->lockForUpdate()
                ->first();

            if ($orderLine === null) {
                continue;
            }

            $received = $reverse
                ? DecimalMath::sub(DecimalMath::of($orderLine->received_quantity), DecimalMath::of($line->quantity))
                : DecimalMath::add(DecimalMath::of($orderLine->received_quantity), DecimalMath::of($line->quantity));

            // Received quantity can never be negative, whatever the reversal order.
            if (DecimalMath::isNegative($received)) {
                $received = DecimalMath::zero(DecimalMath::QUANTITY_SCALE);
            }

            $orderLine->update(['received_quantity' => DecimalMath::quantity($received)]);
        }

        $order = $receipt->purchaseOrder()->first();

        if ($order !== null) {
            $this->refresh($order);
        }
    }

    public function refresh(PurchaseOrder $order): PurchaseOrder
    {
        // A cancelled order stays cancelled; its status is not driven by receipts.
        if ($order->status === PurchaseOrderStatus::Cancelled) {
            return $order;
        }

        $lines = $order->lines()->get();

        $anyReceived = false;
        $allReceived = $lines->isNotEmpty();

        foreach ($lines as $line) {
            $received = DecimalMath::of($line->received_quantity);

            if (DecimalMath::isPositive($received)) {
                $anyReceived = true;
            }

            if (! $line->isFullyReceived()) {
                $allReceived = false;
            }
        }

        $status = match (true) {
            $allReceived => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::PartiallyReceived,
            // Nothing received: fall back to approved, since only an approved order can be
            // received against in the first place.
            default => PurchaseOrderStatus::Approved,
        };

        if ($order->status !== $status) {
            $order->update(['status' => $status]);
        }

        return $order->refresh();
    }
}
