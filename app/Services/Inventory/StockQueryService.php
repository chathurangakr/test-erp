<?php

namespace App\Services\Inventory;

use App\Enums\InventoryDirection;
use App\Models\InventoryTransactionLine;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Derives stock, inventory value and weighted-average cost from posted ledger lines.
 *
 * There is no authoritative stock column anywhere and no cache. Every figure returned here
 * is computed from `inventory_transaction_lines`, so it can always be reconciled against
 * the ledger. See docs/INVENTORY_RULES.md §1.
 *
 * Because each OUT line stores the weighted-average unit cost in force when it was posted,
 * quantity and value are plain sums rather than a ledger replay:
 *
 *   quantity = SUM(IN qty)        - SUM(OUT qty)
 *   value    = SUM(IN total_cost) - SUM(OUT total_cost)
 *   WAC      = value / quantity          (zero when quantity is zero)
 */
class StockQueryService
{
    /**
     * Company-wide quantity on hand for a product, across every warehouse.
     */
    public function quantityForProduct(int $productId): string
    {
        return $this->quantity(
            InventoryTransactionLine::query()->where('product_id', $productId)
        );
    }

    /**
     * Quantity on hand for a product in one warehouse.
     */
    public function quantityForProductInWarehouse(int $productId, int $warehouseId): string
    {
        return $this->quantity(
            InventoryTransactionLine::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
        );
    }

    /**
     * Quantity on hand for a lot, across every warehouse.
     */
    public function quantityForLot(int $lotId): string
    {
        return $this->quantity(
            InventoryTransactionLine::query()->where('lot_id', $lotId)
        );
    }

    /**
     * Quantity on hand for a lot in one warehouse.
     */
    public function quantityForProductWarehouseLot(int $productId, int $warehouseId, ?int $lotId): string
    {
        $query = InventoryTransactionLine::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId);

        $lotId === null
            ? $query->whereNull('lot_id')
            : $query->where('lot_id', $lotId);

        return $this->quantity($query);
    }

    /**
     * Company-wide inventory value for a product.
     */
    public function valueForProduct(int $productId): string
    {
        return $this->value(
            InventoryTransactionLine::query()->where('product_id', $productId)
        );
    }

    /**
     * Company-wide weighted-average unit cost for a product.
     *
     * Version 1 maintains weighted average at company/product level, not per warehouse, so
     * moving stock between warehouses cannot change it. See docs/INVENTORY_RULES.md §8.
     */
    public function weightedAverageCostForProduct(int $productId): string
    {
        $quantity = $this->quantityForProduct($productId);

        if (DecimalMath::isZero($quantity)) {
            return DecimalMath::zero(DecimalMath::COST_SCALE);
        }

        return DecimalMath::cost(
            DecimalMath::div($this->valueForProduct($productId), $quantity)
        );
    }

    /**
     * The authoritative monetary value of issuing `$quantity` from a given balance.
     *
     * Normally `quantity x weighted average`, rounded once. The exception is **full
     * depletion**: when the issue takes the entire remaining company quantity it takes the
     * entire remaining company value, so a product that is sold out is left holding
     * zero quantity AND zero value. Rounding `quantity x average` would instead strand a
     * few millionths of a rupee on a product with nothing in stock, which no later
     * accounting could explain or clear.
     *
     * The balances are passed in rather than read here, because the inventory engine costs
     * lines against a RUNNING balance inside its own transaction, while a transfer costs
     * against the ledger balance. Both must use this one rule.
     */
    public function issueValue(string $quantity, string $onHandQuantity, string $onHandValue): string
    {
        if (DecimalMath::compare($quantity, $onHandQuantity) === 0) {
            return DecimalMath::cost($onHandValue);
        }

        if (DecimalMath::isZero($onHandQuantity)) {
            return DecimalMath::zero(DecimalMath::COST_SCALE);
        }

        $average = DecimalMath::cost(DecimalMath::div($onHandValue, $onHandQuantity));

        return DecimalMath::cost(DecimalMath::mul($quantity, $average));
    }

    /**
     * Secondary quantity on hand (e.g. KG of Raw Coconut Shell) for a product/warehouse.
     *
     * Secondary quantities are recorded only where known, so this total is informational
     * and is deliberately NOT guarded against going negative.
     * See docs/INVENTORY_RULES.md §7.
     */
    public function secondaryQuantityForProductInWarehouse(int $productId, int $warehouseId): string
    {
        return $this->secondaryQuantity(
            InventoryTransactionLine::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
        );
    }

    public function secondaryQuantityForProduct(int $productId): string
    {
        return $this->secondaryQuantity(
            InventoryTransactionLine::query()->where('product_id', $productId)
        );
    }

    /**
     * Per-warehouse balances for every product holding stock or movement history.
     *
     * @return Collection<int, object{product_id: int, warehouse_id: int, quantity: string, secondary_quantity: string, value: string}>
     */
    public function balancesByProductAndWarehouse(?int $productId = null, ?int $warehouseId = null): Collection
    {
        $rows = InventoryTransactionLine::query()
            ->selectRaw('product_id, warehouse_id')
            ->selectRaw($this->signedSum('quantity').' as quantity')
            ->selectRaw($this->signedSum('COALESCE(secondary_quantity, 0)').' as secondary_quantity')
            ->selectRaw($this->signedSum('total_cost').' as value')
            ->when($productId !== null, fn (Builder $query) => $query->where('product_id', $productId))
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('product_id', 'warehouse_id')
            ->get();

        return $rows->map(fn (object $row): object => (object) [
            'product_id' => (int) $row->product_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'quantity' => DecimalMath::quantity(DecimalMath::of($row->quantity ?? '0')),
            'secondary_quantity' => DecimalMath::quantity(DecimalMath::of($row->secondary_quantity ?? '0')),
            'value' => DecimalMath::cost(DecimalMath::of($row->value ?? '0')),
        ]);
    }

    /**
     * Lot balances, optionally narrowed to one lot.
     *
     * @return Collection<int, object{lot_id: int, warehouse_id: int, quantity: string}>
     */
    public function balancesByLot(?int $lotId = null): Collection
    {
        $rows = InventoryTransactionLine::query()
            ->selectRaw('lot_id, warehouse_id')
            ->selectRaw($this->signedSum('quantity').' as quantity')
            ->whereNotNull('lot_id')
            ->when($lotId !== null, fn (Builder $query) => $query->where('lot_id', $lotId))
            ->groupBy('lot_id', 'warehouse_id')
            ->get();

        return $rows->map(fn (object $row): object => (object) [
            'lot_id' => (int) $row->lot_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'quantity' => DecimalMath::quantity(DecimalMath::of($row->quantity ?? '0')),
        ]);
    }

    /**
     * Company-wide weighted average costs keyed by product id.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{quantity: string, value: string, unit_cost: string}>
     */
    public function costSummaryForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = InventoryTransactionLine::query()
            ->selectRaw('product_id')
            ->selectRaw($this->signedSum('quantity').' as quantity')
            ->selectRaw($this->signedSum('total_cost').' as value')
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            $quantity = DecimalMath::quantity(DecimalMath::of($row->quantity ?? '0'));
            $value = DecimalMath::cost(DecimalMath::of($row->value ?? '0'));

            $summary[(int) $row->product_id] = [
                'quantity' => $quantity,
                'value' => $value,
                'unit_cost' => DecimalMath::isZero($quantity)
                    ? DecimalMath::zero(DecimalMath::COST_SCALE)
                    : DecimalMath::cost(DecimalMath::div($value, $quantity)),
            ];
        }

        return $summary;
    }

    /**
     * @param  Builder<InventoryTransactionLine>  $query
     */
    private function quantity(Builder $query): string
    {
        $total = $query->clone()->selectRaw($this->signedSum('quantity').' as total')->value('total');

        return DecimalMath::quantity(DecimalMath::of($total ?? '0'));
    }

    /**
     * @param  Builder<InventoryTransactionLine>  $query
     */
    private function secondaryQuantity(Builder $query): string
    {
        $total = $query->clone()
            ->selectRaw($this->signedSum('COALESCE(secondary_quantity, 0)').' as total')
            ->value('total');

        return DecimalMath::quantity(DecimalMath::of($total ?? '0'));
    }

    /**
     * @param  Builder<InventoryTransactionLine>  $query
     */
    private function value(Builder $query): string
    {
        $total = $query->clone()->selectRaw($this->signedSum('total_cost').' as total')->value('total');

        return DecimalMath::cost(DecimalMath::of($total ?? '0'));
    }

    /**
     * SUM(CASE WHEN direction = 'IN' THEN expr ELSE -expr END)
     *
     * The column name is supplied internally only, never from user input.
     */
    private function signedSum(string $expression): string
    {
        $in = InventoryDirection::In->value;

        return "COALESCE(SUM(CASE WHEN direction = '{$in}' THEN {$expression} ELSE -({$expression}) END), 0)";
    }
}
