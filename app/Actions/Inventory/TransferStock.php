<?php

namespace App\Actions\Inventory;

use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves stock between warehouses as one atomic transaction.
 *
 * Weighted average cost is maintained at company/product level in Version 1, so a transfer
 * must leave company quantity, company value and the weighted average unchanged. That is
 * achieved by giving BOTH lines the same unit cost and the same authoritative total — the
 * average in force at the moment of transfer — so the OUT removes exactly what the IN puts
 * back, to the last decimal.
 *
 * See docs/INVENTORY_RULES.md §8 and §10.
 */
class TransferStock
{
    public function __construct(
        private readonly PostInventoryTransaction $post,
        private readonly StockQueryService $stock,
    ) {}

    /**
     * @param  array{
     *     lot?: InventoryLot|null,
     *     secondary_quantity?: int|string|float|null,
     *     transaction_date?: Carbon|string|null,
     *     notes?: string|null,
     *     posted_by?: int|null,
     *     source_type?: class-string|null,
     *     source_id?: int|null,
     * }  $attributes
     */
    public function handle(
        Product $product,
        Warehouse $from,
        Warehouse $to,
        int|string|float $quantity,
        array $attributes = [],
    ): InventoryTransaction {
        if ($from->is($to)) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('Stock cannot be transferred to the same warehouse.'),
            ]);
        }

        if (! $from->is_active) {
            throw ValidationException::withMessages([
                'from_warehouse_id' => __('The source warehouse :warehouse is inactive.', [
                    'warehouse' => $from->code,
                ]),
            ]);
        }

        if (! $to->is_active) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => __('The destination warehouse :warehouse is inactive.', [
                    'warehouse' => $to->code,
                ]),
            ]);
        }

        $lot = $attributes['lot'] ?? null;

        if ($lot !== null && $lot->product_id !== $product->id) {
            throw ValidationException::withMessages([
                'lot_id' => __('Lot :lot belongs to a different product.', ['lot' => $lot->lot_number]),
            ]);
        }

        $quantity = DecimalMath::of($quantity);
        $secondary = $attributes['secondary_quantity'] ?? null;
        $secondary = $secondary === null ? null : DecimalMath::of($secondary);

        return DB::transaction(function () use ($product, $from, $to, $quantity, $secondary, $lot, $attributes): InventoryTransaction {
            // Both legs share one unit cost AND one authoritative total, so company value
            // and the average cannot move. Deriving the IN value separately from the rounded
            // unit cost would leak a few millionths of a rupee on every transfer.
            $unitCost = $this->stock->weightedAverageCostForProduct($product->id);
            $totalCost = $this->stock->issueValue(
                $quantity,
                $this->stock->quantityForProduct($product->id),
                $this->stock->valueForProduct($product->id),
            );

            $out = (new PendingInventoryLine(
                productId: $product->id,
                warehouseId: $from->id,
                direction: InventoryDirection::Out,
                quantity: $quantity,
                lotId: $lot?->id,
                secondaryQuantity: $secondary,
            ))->withAuthoritativeCost($unitCost, $totalCost);

            $in = (new PendingInventoryLine(
                productId: $product->id,
                warehouseId: $to->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot?->id,
                secondaryQuantity: $secondary,
            ))->withAuthoritativeCost($unitCost, $totalCost);

            return $this->post->handle(
                InventoryTransactionType::Transfer,
                [$out, $in],
                [
                    // A calendar date in the factory's timezone, like every document's own date.
                    'transaction_date' => $attributes['transaction_date'] ?? BusinessClock::todayString(),
                    'notes' => $attributes['notes'] ?? null,
                    'posted_by' => $attributes['posted_by'] ?? null,
                    // The document that moved the stock, when one did (a bag movement).
                    'source_type' => $attributes['source_type'] ?? null,
                    'source_id' => $attributes['source_id'] ?? null,
                ],
            );
        });
    }
}
