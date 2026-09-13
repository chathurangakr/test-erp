<?php

namespace App\Actions\Purchasing;

use App\Actions\Inventory\ReverseInventoryTransaction;
use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Services\Purchasing\PurchaseOrderStatusUpdater;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted goods receipt.
 *
 * The receipt itself is never deleted and its lines are never altered. Reversal:
 *
 *  - reverses the PURCHASE_RECEIPT via the inventory engine, which reuses each original
 *    line's unit cost so quantity and value cancel exactly (docs/INVENTORY_RULES.md I-1);
 *  - restores the linked purchase order's received quantities and recomputes its status;
 *  - marks the receipt CANCELLED with audit references to who reversed it and when.
 *
 * It is refused if the receipt was already reversed, and — because the inventory engine's
 * negative-stock guard still applies — if the received stock has since been issued onward.
 */
class ReverseGoodsReceipt
{
    public function __construct(
        private readonly ReverseInventoryTransaction $reverseInventory,
        private readonly PurchaseOrderStatusUpdater $statusUpdater,
    ) {}

    public function handle(GoodsReceipt $receipt, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): GoodsReceipt {
            $locked = GoodsReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted goods receipt can be reversed. :reference is :status.', [
                        'reference' => $locked->grn_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_inventory_transaction_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Goods receipt :reference has already been reversed.', [
                        'reference' => $locked->grn_number,
                    ]),
                ]);
            }

            $transaction = $locked->inventoryTransaction()->first();

            if ($transaction === null) {
                throw ValidationException::withMessages([
                    'status' => __('Goods receipt :reference has no inventory transaction to reverse.', [
                        'reference' => $locked->grn_number,
                    ]),
                ]);
            }

            // Throws if the stock has already moved on — the negative-stock guard in the
            // inventory engine is what makes that impossible to post.
            $reversal = $this->reverseInventory->handle($transaction, [
                'notes' => __('Reversal of goods receipt :reference', ['reference' => $locked->grn_number]),
                'posted_by' => $userId ?? auth()->id(),
            ]);

            $this->statusUpdater->applyReceipt($locked, $locked->lines()->orderBy('id')->get(), reverse: true);

            $locked->forceFill([
                // REVERSED, not CANCELLED: this receipt did reach inventory and was then
                // undone. Cancelled is reserved for drafts that never posted at all.
                'status' => GoodsReceiptStatus::Reversed,
                'reversal_inventory_transaction_id' => $reversal->getKey(),
                'reversed_at' => now(),
                'reversed_by' => $userId ?? auth()->id(),
            ])->save();

            return $locked->refresh()->load(['lines', 'reversalInventoryTransaction']);
        });
    }
}
