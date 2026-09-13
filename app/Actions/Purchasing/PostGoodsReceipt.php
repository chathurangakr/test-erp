<?php

namespace App\Actions\Purchasing;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryLot;
use App\Models\PurchaseOrderLine;
use App\Services\Purchasing\LandedCostAllocator;
use App\Services\Purchasing\PurchaseOrderStatusUpdater;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft goods receipt: calculates landed cost, creates lots, and writes the
 * PURCHASE_RECEIPT movements to the inventory ledger.
 *
 * Everything happens inside ONE database transaction. If any step fails there is no
 * inventory movement, no lot, no purchase-order update and no partially posted receipt.
 *
 * Inventory arithmetic is NOT done here — the receipt hands its final unit costs to
 * App\Actions\Inventory\PostInventoryTransaction, which owns locking, negative-stock
 * checking and weighted-average costing. See docs/BUSINESS_RULES.md §12.
 */
class PostGoodsReceipt
{
    public function __construct(
        private readonly PostInventoryTransaction $postInventory,
        private readonly CreateInventoryLot $createLot,
        private readonly LandedCostAllocator $allocator,
        private readonly PurchaseOrderStatusUpdater $statusUpdater,
    ) {}

    public function handle(GoodsReceipt $receipt, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): GoodsReceipt {
            // Re-read under lock so two concurrent posts cannot both pass the draft check.
            $locked = GoodsReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $lines = $locked->lines()->with('product')->orderBy('id')->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => __('A goods receipt must contain at least one line before posting.'),
                ]);
            }

            $this->validateLines($locked, $lines);
            $this->validatePurchaseOrder($locked, $lines);

            $allocation = $this->allocator->allocate(
                $lines->map(fn (GoodsReceiptLine $line): array => [
                    'quantity' => DecimalMath::of($line->quantity),
                    'unit_price' => DecimalMath::of($line->unit_price),
                ])->all(),
                $locked->landedCostTotal(),
            );

            $pendingLines = [];

            foreach ($lines as $index => $line) {
                $costs = $allocation['lines'][$index];

                $lot = $this->resolveLot($locked, $line);

                // Persist how the cost was derived, so a posted receipt can be explained.
                $line->forceFill([
                    'material_cost' => $costs['material_cost'],
                    'allocated_landed_cost' => $costs['allocated_landed_cost'],
                    'final_unit_cost' => $costs['final_unit_cost'],
                    'lot_id' => $lot?->id,
                ])->save();

                $pendingLines[] = new PendingInventoryLine(
                    productId: $line->product_id,
                    warehouseId: $locked->warehouse_id,
                    direction: InventoryDirection::In,
                    quantity: DecimalMath::of($line->quantity),
                    lotId: $lot?->id,
                    secondaryQuantity: $line->secondary_quantity === null
                        ? null
                        : DecimalMath::of($line->secondary_quantity),
                    // The landed unit cost is what reaches inventory, not the raw price.
                    unitCost: $costs['final_unit_cost'],
                );
            }

            $transaction = $this->postInventory->handle(
                InventoryTransactionType::PurchaseReceipt,
                $pendingLines,
                [
                    'transaction_date' => $locked->receipt_date,
                    'source_type' => GoodsReceipt::class,
                    'source_id' => $locked->getKey(),
                    'notes' => __('Goods receipt :reference', ['reference' => $locked->grn_number]),
                    'posted_by' => $userId ?? auth()->id(),
                ],
            );

            $this->statusUpdater->applyReceipt($locked, $lines, reverse: false);

            $locked->forceFill([
                'status' => GoodsReceiptStatus::Posted,
                'inventory_transaction_id' => $transaction->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            return $locked->refresh()->load(['lines', 'inventoryTransaction']);
        });
    }

    private function assertPostable(GoodsReceipt $receipt): void
    {
        if (! $receipt->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft goods receipt can be posted. :reference is :status.', [
                    'reference' => $receipt->grn_number,
                    'status' => $receipt->status->label(),
                ]),
            ]);
        }

        // Belt and braces against double posting: a draft should never already hold a
        // ledger link, but if it somehow does, refuse rather than post twice.
        if ($receipt->inventory_transaction_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Goods receipt :reference has already been posted to inventory.', [
                    'reference' => $receipt->grn_number,
                ]),
            ]);
        }

        if (! $receipt->supplier?->is_active) {
            throw ValidationException::withMessages([
                'supplier_id' => __('Goods cannot be received from an inactive supplier.'),
            ]);
        }

        if (! $receipt->warehouse?->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('Goods cannot be received into an inactive warehouse.'),
            ]);
        }
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $lines
     */
    private function validateLines(GoodsReceipt $receipt, $lines): void
    {
        foreach ($lines as $index => $line) {
            $field = "lines.{$index}";

            if ($line->product === null) {
                throw ValidationException::withMessages([
                    $field.'.product_id' => __('The selected product does not exist.'),
                ]);
            }

            // The inventory engine enforces this too; checking here gives a clearer message
            // and avoids doing lot work that would only be rolled back.
            if (! $line->product->is_active) {
                throw ValidationException::withMessages([
                    $field.'.product_id' => __('Product :product is inactive and cannot receive new stock.', [
                        'product' => $line->product->code,
                    ]),
                ]);
            }

            if (! DecimalMath::isPositive(DecimalMath::of($line->quantity))) {
                throw ValidationException::withMessages([
                    $field.'.quantity' => __('The received quantity must be greater than zero.'),
                ]);
            }

            if (DecimalMath::isNegative(DecimalMath::of($line->unit_price))) {
                throw ValidationException::withMessages([
                    $field.'.unit_price' => __('The purchase price cannot be negative.'),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $lines
     */
    private function validatePurchaseOrder(GoodsReceipt $receipt, $lines): void
    {
        if ($receipt->purchase_order_id === null) {
            return;
        }

        $order = $receipt->purchaseOrder()->lockForUpdate()->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('The linked purchase order no longer exists.'),
            ]);
        }

        if (! $order->status->canReceive()) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('Purchase order :reference is :status and cannot be received against.', [
                    'reference' => $order->po_number,
                    'status' => $order->status->label(),
                ]),
            ]);
        }

        if ($order->supplier_id !== $receipt->supplier_id) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('The goods receipt supplier does not match the purchase order supplier.'),
            ]);
        }

        // Over-receipt guard, accumulated per PO line in case one receipt has several lines
        // pointing at the same order line.
        $requested = [];

        foreach ($lines as $index => $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }

            $requested[$line->purchase_order_line_id] = DecimalMath::add(
                $requested[$line->purchase_order_line_id] ?? DecimalMath::zero(),
                DecimalMath::of($line->quantity),
            );
        }

        foreach ($requested as $orderLineId => $quantity) {
            $orderLine = PurchaseOrderLine::query()->whereKey($orderLineId)->lockForUpdate()->first();

            if ($orderLine === null || $orderLine->purchase_order_id !== $order->getKey()) {
                throw ValidationException::withMessages([
                    'lines' => __('A goods receipt line refers to a purchase order line that does not belong to this order.'),
                ]);
            }

            $outstanding = $orderLine->outstandingQuantity();

            if (DecimalMath::compare($quantity, DecimalMath::of($outstanding)) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('Receiving :quantity exceeds the outstanding quantity of :outstanding on the purchase order.', [
                        'quantity' => DecimalMath::quantity($quantity),
                        'outstanding' => $outstanding,
                    ]),
                ]);
            }
        }
    }

    /**
     * Use the lot already chosen on the line, or create one for this receipt.
     *
     * A lot is created per line so traceability back to the delivery is preserved. An
     * explicitly referenced lot must belong to the same product.
     */
    private function resolveLot(GoodsReceipt $receipt, GoodsReceiptLine $line): ?InventoryLot
    {
        if ($line->lot_id !== null) {
            $lot = InventoryLot::query()->whereKey($line->lot_id)->first();

            if ($lot === null) {
                throw ValidationException::withMessages([
                    'lines' => __('The selected lot does not exist.'),
                ]);
            }

            if ($lot->product_id !== $line->product_id) {
                throw ValidationException::withMessages([
                    'lines' => __('Lot :lot belongs to a different product.', ['lot' => $lot->lot_number]),
                ]);
            }

            return $lot;
        }

        return $this->createLot->handle($line->product, [
            'supplier_lot_number' => $line->supplier_lot_number,
            'source_type' => GoodsReceipt::class,
            'source_id' => $receipt->getKey(),
            'received_at' => $receipt->receipt_date,
            'notes' => __('Created by goods receipt :reference', ['reference' => $receipt->grn_number]),
        ]);
    }
}
