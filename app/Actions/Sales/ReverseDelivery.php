<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\ReverseInventoryTransaction;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Services\Accounting\SalesBillingReconciliationService;
use App\Services\Sales\SalesOrderStatusUpdater;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted delivery.
 *
 * The delivery itself is never deleted and its lines are never altered. Reversal:
 *
 *  - reverses the SALES_ISSUE through the inventory engine, which reuses each original
 *    line's unit cost, so the stock returns at exactly the value it left at
 *    (docs/INVENTORY_RULES.md I-1) — the weighted average is therefore restored, not
 *    distorted by whatever it has drifted to since;
 *  - restores the linked sales order's delivered quantities and recomputes its status;
 *  - marks the delivery REVERSED with audit references to who reversed it and when.
 *
 * It is refused if the delivery was already reversed, and — because the engine's rules
 * still apply — if reversing would violate them.
 */
class ReverseDelivery
{
    public function __construct(
        private readonly ReverseInventoryTransaction $reverseInventory,
        private readonly RecordOperationalJournal $accounting,
        private readonly SalesBillingReconciliationService $billing,
        private readonly SalesOrderStatusUpdater $statusUpdater,
    ) {}

    public function handle(Delivery $delivery, ?int $userId = null): Delivery
    {
        return DB::transaction(function () use ($delivery, $userId): Delivery {
            $locked = Delivery::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted delivery can be reversed. :reference is :status.', [
                        'reference' => $locked->delivery_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_inventory_transaction_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Delivery :reference has already been reversed.', [
                        'reference' => $locked->delivery_number,
                    ]),
                ]);
            }

            $this->assertNotInvoiced($locked);

            $transaction = $locked->inventoryTransaction()->first();

            if ($transaction === null) {
                throw ValidationException::withMessages([
                    'status' => __('Delivery :reference has no inventory transaction to reverse.', [
                        'reference' => $locked->delivery_number,
                    ]),
                ]);
            }

            $reversal = $this->reverseInventory->handle($transaction, [
                'notes' => RecordText::of('Reversal of delivery :reference', ['reference' => $locked->delivery_number]),
                'posted_by' => $userId ?? auth()->id(),
            ]);

            // Any raw material the delivery backflushed goes back too, at its original cost.
            $backflush = $locked->backflushTransaction()->first();
            $backflushReversal = $backflush === null ? null : $this->reverseInventory->handle($backflush, [
                'notes' => RecordText::of('Reversal of raw material backflushed by delivery :reference', [
                    'reference' => $locked->delivery_number,
                ]),
                'posted_by' => $userId ?? auth()->id(),
            ]);

            $this->statusUpdater->applyDelivery($locked, $locked->lines()->orderBy('id')->get(), reverse: true);

            $this->accounting->reverseFor($locked, $userId);

            $locked->forceFill([
                // REVERSED, not CANCELLED: stock really left and was put back.
                'status' => DeliveryStatus::Reversed,
                'reversal_inventory_transaction_id' => $reversal->getKey(),
                'backflush_reversal_transaction_id' => $backflushReversal?->getKey(),
                'reversed_at' => now(),
                'reversed_by' => $userId ?? auth()->id(),
            ])->save();

            return $locked->refresh()->load(['lines', 'reversalInventoryTransaction']);
        });
    }

    /**
     * A delivery whose revenue has been invoiced cannot be reversed.
     *
     * Reversing it removes the cost of sales and puts the stock back, but the sales invoice
     * has already recognised the revenue — the income statement would show a sale with
     * nothing sold against it. The invoice has to be reversed first, which releases the
     * billing.
     */
    private function assertNotInvoiced(Delivery $delivery): void
    {
        $delivery->loadMissing('lines');
        $billed = $this->billing->deliveryBilled($delivery);

        if (! DecimalMath::isPositive($billed)) {
            return;
        }

        $invoices = $this->billing->billingLines($delivery)
            ->map(fn ($line): ?string => $line->invoice?->invoice_number)
            ->filter()
            ->unique()
            ->implode(', ');

        throw ValidationException::withMessages([
            'status' => __('Delivery :reference has :billed of revenue invoiced by :invoices. Reverse the associated sales invoice(s) first.', [
                'reference' => $delivery->delivery_number,
                'billed' => $billed,
                'invoices' => $invoices,
            ]),
        ]);
    }
}
