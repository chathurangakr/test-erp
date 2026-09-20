<?php

namespace App\Services\Sales;

use App\Enums\SalesOrderStatus;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Recomputes a sales order's status from its lines' delivered quantities.
 *
 * Called after a delivery is posted or reversed. The status is always derived from the line
 * quantities rather than incremented, so posting and reversal cannot leave it stale.
 */
class SalesOrderStatusUpdater
{
    /**
     * Apply a delivery's quantities to the linked sales order lines, then recompute the
     * order status. Posting adds; reversal subtracts.
     *
     * @param  Collection<int, DeliveryLine>  $lines
     */
    public function applyDelivery(Delivery $delivery, Collection $lines, bool $reverse): void
    {
        if ($delivery->sales_order_id === null) {
            return;
        }

        foreach ($lines as $line) {
            if ($line->sales_order_line_id === null) {
                continue;
            }

            $orderLine = SalesOrderLine::query()
                ->whereKey($line->sales_order_line_id)
                ->lockForUpdate()
                ->first();

            if ($orderLine === null) {
                continue;
            }

            $delivered = $reverse
                ? DecimalMath::sub(DecimalMath::of($orderLine->delivered_quantity), DecimalMath::of($line->quantity))
                : DecimalMath::add(DecimalMath::of($orderLine->delivered_quantity), DecimalMath::of($line->quantity));

            if (DecimalMath::isNegative($delivered)) {
                $delivered = DecimalMath::zero(DecimalMath::QUANTITY_SCALE);
            }

            $attributes = ['delivered_quantity' => DecimalMath::quantity($delivered)];

            // Secondary quantities are tracked alongside, where the line carries them.
            if ($line->secondary_quantity !== null) {
                $secondary = $reverse
                    ? DecimalMath::sub(
                        DecimalMath::of($orderLine->delivered_secondary_quantity ?? '0'),
                        DecimalMath::of($line->secondary_quantity),
                    )
                    : DecimalMath::add(
                        DecimalMath::of($orderLine->delivered_secondary_quantity ?? '0'),
                        DecimalMath::of($line->secondary_quantity),
                    );

                if (DecimalMath::isNegative($secondary)) {
                    $secondary = DecimalMath::zero(DecimalMath::QUANTITY_SCALE);
                }

                $attributes['delivered_secondary_quantity'] = DecimalMath::quantity($secondary);
            }

            $orderLine->update($attributes);
        }

        $order = $delivery->salesOrder()->first();

        if ($order !== null) {
            $this->refresh($order);
        }
    }

    public function refresh(SalesOrder $order): SalesOrder
    {
        // A cancelled order stays cancelled; its status is not driven by deliveries.
        if ($order->status === SalesOrderStatus::Cancelled) {
            return $order;
        }

        $lines = $order->lines()->get();

        $anyDelivered = false;
        $allDelivered = $lines->isNotEmpty();

        foreach ($lines as $line) {
            if (DecimalMath::isPositive(DecimalMath::of($line->delivered_quantity))) {
                $anyDelivered = true;
            }

            if (! $line->isFullyDelivered()) {
                $allDelivered = false;
            }
        }

        $status = match (true) {
            $allDelivered => SalesOrderStatus::Delivered,
            $anyDelivered => SalesOrderStatus::PartiallyDelivered,
            default => SalesOrderStatus::Approved,
        };

        if ($order->status !== $status) {
            $order->update(['status' => $status]);
        }

        return $order->refresh();
    }
}
