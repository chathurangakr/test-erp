<?php

namespace App\Data\Inventory;

use App\Enums\InventoryDirection;
use App\Support\DecimalMath;

/**
 * One movement requested from the inventory posting service.
 *
 * This is an instruction, not a ledger record: quantities are normalised here, but costing
 * and validation happen in App\Actions\Inventory\PostInventoryTransaction.
 */
final class PendingInventoryLine
{
    /**
     * @param  string  $quantity  Always positive; direction alone decides the sign.
     * @param  string|null  $unitCost  Required for IN. For OUT it must be null — the
     *                                 service costs outgoing movements at the weighted
     *                                 average in force at posting time. The only exceptions
     *                                 are the trusted domain services, which set
     *                                 $systemUnitCost internally.
     * @param  string|null  $systemUnitCost  Internal cost override. Never accept this from
     *                                       user input.
     * @param  string|null  $systemTotalCost  The AUTHORITATIVE monetary value of this
     *                                        movement, supplied by a trusted domain service
     *                                        that calculated it exactly. When present the
     *                                        engine stores it unchanged instead of
     *                                        reconstructing quantity x unit cost. Never
     *                                        accept this from user input.
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly InventoryDirection $direction,
        public readonly string $quantity,
        public readonly ?int $lotId = null,
        public readonly ?string $secondaryQuantity = null,
        public readonly ?string $unitCost = null,
        public readonly ?string $systemUnitCost = null,
        public readonly ?string $systemTotalCost = null,
    ) {}

    /**
     * @param  array{
     *     product_id: int,
     *     warehouse_id: int,
     *     direction: InventoryDirection|string,
     *     quantity: int|string|float,
     *     lot_id?: int|null,
     *     secondary_quantity?: int|string|float|null,
     *     unit_cost?: int|string|float|null,
     * }  $attributes
     *
     * Deliberately has NO total-cost key. An authoritative line total may only be supplied
     * through withAuthoritativeCost() by a trusted domain service, so no request payload —
     * however it is shaped — can set the monetary value of a stock movement.
     */
    public static function fromArray(array $attributes): self
    {
        $direction = $attributes['direction'] instanceof InventoryDirection
            ? $attributes['direction']
            : InventoryDirection::from((string) $attributes['direction']);

        $secondary = $attributes['secondary_quantity'] ?? null;
        $unitCost = $attributes['unit_cost'] ?? null;

        return new self(
            productId: (int) $attributes['product_id'],
            warehouseId: (int) $attributes['warehouse_id'],
            direction: $direction,
            quantity: DecimalMath::of($attributes['quantity']),
            lotId: isset($attributes['lot_id']) ? (int) $attributes['lot_id'] : null,
            secondaryQuantity: $secondary === null ? null : DecimalMath::of($secondary),
            unitCost: $unitCost === null ? null : DecimalMath::of($unitCost),
        );
    }

    /**
     * Copy this line with a cost the calling domain service calculated exactly.
     *
     * `$totalCost` is the authoritative monetary value of the movement and is stored
     * unchanged; `$unitCost` is its rounded per-unit representation, normally
     * `round($totalCost / quantity, 6)`. The engine rejects a pair that cannot be explained
     * by that rounding, so the two cannot silently disagree.
     *
     * Used by purchasing (exact landed line value), all three production types (the
     * allocator's exact allocated cost), transfers (one total shared by both legs) and
     * reversals (the original line's total). Never reachable from a request.
     */
    public function withAuthoritativeCost(string $unitCost, string $totalCost): self
    {
        return new self(
            productId: $this->productId,
            warehouseId: $this->warehouseId,
            direction: $this->direction,
            quantity: $this->quantity,
            lotId: $this->lotId,
            secondaryQuantity: $this->secondaryQuantity,
            unitCost: null,
            systemUnitCost: $unitCost,
            systemTotalCost: $totalCost,
        );
    }

    /**
     * Key identifying the stock bucket this line moves: product + warehouse + lot.
     */
    public function bucketKey(): string
    {
        return $this->productId.':'.$this->warehouseId.':'.($this->lotId ?? 'null');
    }

    public function warehouseKey(): string
    {
        return $this->productId.':'.$this->warehouseId;
    }
}
