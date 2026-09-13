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
     *                                 are transfers and reversals, which set
     *                                 $systemUnitCost internally.
     * @param  string|null  $systemUnitCost  Internal cost override. Never accept this from
     *                                       user input.
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
     * Copy this line with an internally determined unit cost (transfers, reversals).
     */
    public function withSystemUnitCost(string $unitCost): self
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
