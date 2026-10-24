<?php

namespace App\Actions\Inventory;

use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionLine;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single entry point for writing to the inventory ledger.
 *
 * Controllers never perform inventory arithmetic; every module (purchasing, sales,
 * production) posts through this action so the same guarantees apply everywhere:
 *
 *  - the whole transaction is written inside one database transaction — all lines or none;
 *  - product rows are locked FOR UPDATE so concurrent posts cannot both pass the
 *    negative-stock check;
 *  - OUT movements are costed at the weighted average in force at the moment of posting;
 *  - any line that would drive stock below zero aborts the entire transaction;
 *  - `total_cost` is the AUTHORITATIVE monetary value of a movement. A trusted domain
 *    service may supply it exactly (see PendingInventoryLine::withAuthoritativeCost);
 *    `unit_cost` is only its rounded per-unit representation.
 *
 * See docs/INVENTORY_RULES.md §§1-4, 8-9.
 */
class PostInventoryTransaction
{
    public function __construct(
        private readonly StockQueryService $stock,
        private readonly GenerateInventoryReference $references,
    ) {}

    /**
     * @param  list<PendingInventoryLine>  $lines
     * @param  array{
     *     transaction_date?: Carbon|string|null,
     *     source_type?: string|null,
     *     source_id?: int|null,
     *     reversal_of_id?: int|null,
     *     notes?: string|null,
     *     posted_by?: int|null,
     *     reference?: string|null,
     * }  $attributes
     */
    public function handle(
        InventoryTransactionType $type,
        array $lines,
        array $attributes = [],
    ): InventoryTransaction {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => __('An inventory transaction must contain at least one line.'),
            ]);
        }

        return DB::transaction(function () use ($type, $lines, $attributes): InventoryTransaction {
            $products = $this->lockProducts($lines);
            $warehouses = $this->loadWarehouses($lines);
            $lots = $this->loadLots($lines);

            $this->validateLines($type, $lines, $products, $warehouses, $lots);

            $costed = $this->costAndCheckLines($lines, $products);

            $transaction = InventoryTransaction::create([
                'reference' => $attributes['reference'] ?? $this->references->forTransaction(),
                'transaction_type' => $type,
                // A calendar date in the factory's timezone, like every document's own date.
                'transaction_date' => $attributes['transaction_date'] ?? BusinessClock::todayString(),
                'source_type' => $attributes['source_type'] ?? null,
                'source_id' => $attributes['source_id'] ?? null,
                'reversal_of_id' => $attributes['reversal_of_id'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'posted_by' => $attributes['posted_by'] ?? auth()->id(),
                'posted_at' => now(),
            ]);

            foreach ($costed as $line) {
                InventoryTransactionLine::create([
                    'inventory_transaction_id' => $transaction->id,
                    'product_id' => $line['product_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'lot_id' => $line['lot_id'],
                    'direction' => $line['direction'],
                    'quantity' => $line['quantity'],
                    'secondary_quantity' => $line['secondary_quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'total_cost' => $line['total_cost'],
                ]);
            }

            return $transaction->load('lines');
        });
    }

    /**
     * Lock every product involved for the duration of the transaction.
     *
     * Weighted average cost is maintained at company/product level, so the product row is
     * the correct thing to serialise on: two concurrent posts touching the same product
     * queue up rather than both reading the same pre-post balance.
     *
     * @param  list<PendingInventoryLine>  $lines
     * @return array<int, Product>
     */
    private function lockProducts(array $lines): array
    {
        $ids = array_values(array_unique(array_map(
            fn (PendingInventoryLine $line): int => $line->productId,
            $lines,
        )));
        sort($ids); // Consistent lock ordering avoids deadlocks between concurrent posts.

        return Product::query()
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  list<PendingInventoryLine>  $lines
     * @return array<int, Warehouse>
     */
    private function loadWarehouses(array $lines): array
    {
        $ids = array_values(array_unique(array_map(
            fn (PendingInventoryLine $line): int => $line->warehouseId,
            $lines,
        )));

        return Warehouse::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * @param  list<PendingInventoryLine>  $lines
     * @return array<int, InventoryLot>
     */
    private function loadLots(array $lines): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (PendingInventoryLine $line): ?int => $line->lotId,
            $lines,
        ))));

        if ($ids === []) {
            return [];
        }

        return InventoryLot::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * @param  list<PendingInventoryLine>  $lines
     * @param  array<int, Product>  $products
     * @param  array<int, Warehouse>  $warehouses
     * @param  array<int, InventoryLot>  $lots
     */
    private function validateLines(
        InventoryTransactionType $type,
        array $lines,
        array $products,
        array $warehouses,
        array $lots,
    ): void {
        foreach ($lines as $index => $line) {
            $field = "lines.{$index}";

            $product = $products[$line->productId] ?? null;

            if ($product === null) {
                throw ValidationException::withMessages([
                    $field.'.product_id' => __('The selected product does not exist.'),
                ]);
            }

            $warehouse = $warehouses[$line->warehouseId] ?? null;

            if ($warehouse === null) {
                throw ValidationException::withMessages([
                    $field.'.warehouse_id' => __('The selected warehouse does not exist.'),
                ]);
            }

            if (! $warehouse->is_active) {
                throw ValidationException::withMessages([
                    $field.'.warehouse_id' => __('Stock cannot be posted to an inactive warehouse.'),
                ]);
            }

            // The place customers' returnable bags are counted is not a store: only a bag
            // movement (a transfer in or out, a loss written off) or a reversal may touch it.
            // See docs/INVENTORY_RULES.md §18.
            if ($warehouse->holds_customer_items && ! in_array($type, [
                InventoryTransactionType::Transfer,
                InventoryTransactionType::AdjustmentOut,
                InventoryTransactionType::Reversal,
            ], true)) {
                throw ValidationException::withMessages([
                    $field.'.warehouse_id' => __('Warehouse :warehouse only counts bags customers are holding. Choose a real store.', [
                        'warehouse' => $warehouse->code,
                    ]),
                ]);
            }

            // Inactive products may be issued OUT so existing stock can be cleared, but
            // must never receive NEW stock. Transfers and reversals are exempt: they move
            // or undo stock that already exists. See docs/INVENTORY_RULES.md §14.
            if ($line->direction->isIn() && $type->bringsNewStock() && ! $product->is_active) {
                throw ValidationException::withMessages([
                    $field.'.product_id' => __('Product :product is inactive and cannot receive new stock.', [
                        'product' => $product->code,
                    ]),
                ]);
            }

            if (! DecimalMath::isPositive($line->quantity)) {
                throw ValidationException::withMessages([
                    $field.'.quantity' => __('Quantity must be greater than zero. Direction determines whether stock rises or falls.'),
                ]);
            }

            $this->validatePrecision(
                $line->quantity,
                (int) $product->primaryUom->decimal_places,
                $product->primaryUom->code,
                $field.'.quantity',
            );

            $this->validateSecondaryQuantity($line, $product, $field);
            $this->validateLot($type, $line, $product, $lots, $field);
            $this->validateSuppliedCost($line, $field);
        }
    }

    private function validateSecondaryQuantity(PendingInventoryLine $line, Product $product, string $field): void
    {
        if ($line->secondaryQuantity === null) {
            return;
        }

        if ($product->secondary_uom_id === null) {
            throw ValidationException::withMessages([
                $field.'.secondary_quantity' => __('A secondary quantity cannot be recorded for :product, which has no secondary unit of measure.', [
                    'product' => $product->code,
                ]),
            ]);
        }

        if (DecimalMath::isNegative($line->secondaryQuantity)) {
            throw ValidationException::withMessages([
                $field.'.secondary_quantity' => __('The secondary quantity cannot be negative.'),
            ]);
        }

        $this->validatePrecision(
            $line->secondaryQuantity,
            (int) $product->secondaryUom->decimal_places,
            $product->secondaryUom->code,
            $field.'.secondary_quantity',
        );
    }

    /**
     * @param  array<int, InventoryLot>  $lots
     */
    private function validateLot(
        InventoryTransactionType $type,
        PendingInventoryLine $line,
        Product $product,
        array $lots,
        string $field,
    ): void {
        if ($line->lotId === null) {
            return;
        }

        // A pooled product receives stock into its lot-free pool only. Its old lots may still
        // be drawn down, a reversal may put stock back exactly where it came from, and a lot
        // consolidation may bring an overdrawn old lot back to zero — but no new stock may
        // land in a lot (docs/INVENTORY_RULES.md §15).
        $mayRefillLot = in_array($type, [InventoryTransactionType::Reversal, InventoryTransactionType::LotConsolidation], true);

        if ($line->direction->isIn() && ! $product->tracksLots() && ! $mayRefillLot) {
            throw ValidationException::withMessages([
                $field.'.lot_id' => __(':product is kept as one pooled stock without lots, so no lot can be chosen.', [
                    'product' => $product->code,
                ]),
            ]);
        }

        $lot = $lots[$line->lotId] ?? null;

        if ($lot === null) {
            throw ValidationException::withMessages([
                $field.'.lot_id' => __('The selected lot does not exist.'),
            ]);
        }

        if ($lot->product_id !== $line->productId) {
            throw ValidationException::withMessages([
                $field.'.lot_id' => __('Lot :lot belongs to a different product and cannot be used for this movement.', [
                    'lot' => $lot->lot_number,
                ]),
            ]);
        }

        if ($line->direction->isIn() && ! $lot->is_active) {
            throw ValidationException::withMessages([
                $field.'.lot_id' => __('Stock cannot be received into the inactive lot :lot.', [
                    'lot' => $lot->lot_number,
                ]),
            ]);
        }
    }

    /**
     * Callers may cost incoming stock but never outgoing stock — outgoing movements are
     * always valued at the weighted average in force when they are posted.
     */
    private function validateSuppliedCost(PendingInventoryLine $line, string $field): void
    {
        if ($line->direction->isIn()) {
            if ($line->unitCost === null && $line->systemUnitCost === null) {
                throw ValidationException::withMessages([
                    $field.'.unit_cost' => __('A unit cost is required for incoming stock.'),
                ]);
            }

            $cost = $line->systemUnitCost ?? $line->unitCost;

            if ($cost !== null && DecimalMath::isNegative($cost)) {
                throw ValidationException::withMessages([
                    $field.'.unit_cost' => __('The unit cost cannot be negative.'),
                ]);
            }

            return;
        }

        if ($line->unitCost !== null) {
            throw ValidationException::withMessages([
                $field.'.unit_cost' => __('Outgoing stock is valued at the current weighted average cost and cannot be priced manually.'),
            ]);
        }
    }

    private function validatePrecision(string $value, int $decimalPlaces, string $uomCode, string $field): void
    {
        if (DecimalMath::decimalPlaces($value) > $decimalPlaces) {
            throw ValidationException::withMessages([
                $field => __(':uom allows at most :places decimal place(s).', [
                    'uom' => $uomCode,
                    'places' => $decimalPlaces,
                ]),
            ]);
        }
    }

    /**
     * Walk the lines in order, costing each one and checking it against a running balance.
     *
     * Order matters: a receipt earlier in the same transaction changes the weighted average
     * used by a later issue, exactly as it would across separate transactions.
     *
     * @param  list<PendingInventoryLine>  $lines
     * @param  array<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    private function costAndCheckLines(array $lines, array $products): array
    {
        /** @var array<int, array{quantity: string, value: string}> $company */
        $company = [];
        /** @var array<string, string> $buckets */
        $buckets = [];
        /** @var array<string, string> $warehouseBalances */
        $warehouseBalances = [];

        $costed = [];

        foreach ($lines as $index => $line) {
            $productId = $line->productId;

            // Seed running state from the ledger on first touch.
            if (! isset($company[$productId])) {
                $company[$productId] = [
                    'quantity' => $this->stock->quantityForProduct($productId),
                    'value' => $this->stock->valueForProduct($productId),
                ];
            }

            $warehouseKey = $line->warehouseKey();

            if (! isset($warehouseBalances[$warehouseKey])) {
                $warehouseBalances[$warehouseKey] = $this->stock
                    ->quantityForProductInWarehouse($productId, $line->warehouseId);
            }

            $bucketKey = $line->bucketKey();

            if ($line->lotId !== null && ! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = $this->stock
                    ->quantityForProductWarehouseLot($productId, $line->warehouseId, $line->lotId);
            }

            if ($line->direction->isIn()) {
                $unitCost = DecimalMath::cost($line->systemUnitCost ?? $line->unitCost ?? '0');

                // An authoritative total is stored exactly as the calling domain service
                // calculated it. Only when none is supplied does the engine reconstruct the
                // value from the rounded unit cost.
                $totalCost = $line->systemTotalCost !== null
                    ? DecimalMath::cost($line->systemTotalCost)
                    : DecimalMath::cost(DecimalMath::mul($line->quantity, $unitCost));

                $this->assertCostRepresentation($line, $unitCost, $totalCost, $index);

                $company[$productId]['quantity'] = DecimalMath::add($company[$productId]['quantity'], $line->quantity);
                $company[$productId]['value'] = DecimalMath::add($company[$productId]['value'], $totalCost);
                $warehouseBalances[$warehouseKey] = DecimalMath::add($warehouseBalances[$warehouseKey], $line->quantity);

                if ($line->lotId !== null) {
                    $buckets[$bucketKey] = DecimalMath::add($buckets[$bucketKey], $line->quantity);
                }
            } else {
                // Outgoing: valued at the weighted average in force right now, unless an
                // internal caller (transfer, reversal) supplied the cost to use.
                $unitCost = $line->systemUnitCost !== null
                    ? DecimalMath::cost($line->systemUnitCost)
                    : $this->currentAverage($company[$productId]);

                if ($line->systemTotalCost !== null) {
                    // A reversal removes exactly what the original line added; a transfer's
                    // two legs share one figure. Neither may be re-derived from a rounded
                    // unit cost.
                    $totalCost = DecimalMath::cost($line->systemTotalCost);
                } elseif ($line->systemUnitCost !== null) {
                    $totalCost = DecimalMath::cost(DecimalMath::mul($line->quantity, $unitCost));
                } else {
                    // The ordinary case — a sale or a production consumption — priced at the
                    // running weighted average, and taking ALL the remaining value when it
                    // takes all the remaining quantity.
                    $totalCost = $this->stock->issueValue(
                        $line->quantity,
                        $company[$productId]['quantity'],
                        $company[$productId]['value'],
                    );
                }

                $this->assertCostRepresentation($line, $unitCost, $totalCost, $index);

                $newWarehouseBalance = DecimalMath::sub($warehouseBalances[$warehouseKey], $line->quantity);

                if (DecimalMath::isNegative($newWarehouseBalance)) {
                    $this->rejectNegative(
                        $products[$productId],
                        $line,
                        $warehouseBalances[$warehouseKey],
                        "lines.{$index}.quantity",
                    );
                }

                if ($line->lotId !== null) {
                    $newLotBalance = DecimalMath::sub($buckets[$bucketKey], $line->quantity);

                    if (DecimalMath::isNegative($newLotBalance)) {
                        $this->rejectNegative(
                            $products[$productId],
                            $line,
                            $buckets[$bucketKey],
                            "lines.{$index}.quantity",
                            isLot: true,
                        );
                    }

                    $buckets[$bucketKey] = $newLotBalance;
                }

                $warehouseBalances[$warehouseKey] = $newWarehouseBalance;
                $company[$productId]['quantity'] = DecimalMath::sub($company[$productId]['quantity'], $line->quantity);
                $company[$productId]['value'] = DecimalMath::sub($company[$productId]['value'], $totalCost);
            }

            $costed[] = [
                'product_id' => $productId,
                'warehouse_id' => $line->warehouseId,
                'lot_id' => $line->lotId,
                'direction' => $line->direction,
                'quantity' => DecimalMath::quantity($line->quantity),
                'secondary_quantity' => $line->secondaryQuantity === null
                    ? null
                    : DecimalMath::quantity($line->secondaryQuantity),
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
            ];
        }

        return $costed;
    }

    /**
     * The line's two money columns must tell the same story.
     *
     * `total_cost` is the authoritative monetary value of the movement and `unit_cost` is
     * its rounded per-unit representation, so the two may differ only by the rounding
     * itself: at most half a unit-cost tick per unit, i.e. `quantity x 0.0000005`. Anything
     * wider means the caller supplied a contradictory pair — a mistake that would otherwise
     * be invisible until someone tried to reconcile the ledger.
     *
     * A negative value is refused outright: stock cannot be worth less than nothing.
     */
    private function assertCostRepresentation(
        PendingInventoryLine $line,
        string $unitCost,
        string $totalCost,
        int $index,
    ): void {
        if (DecimalMath::isNegative($totalCost)) {
            throw ValidationException::withMessages([
                "lines.{$index}.total_cost" => __('An inventory line value cannot be negative.'),
            ]);
        }

        $reconstructed = DecimalMath::mul($line->quantity, $unitCost);
        $difference = DecimalMath::sub($totalCost, $reconstructed);
        $tolerance = DecimalMath::mul($line->quantity, '0.0000005');

        if (DecimalMath::compare($difference, $tolerance) > 0
            || DecimalMath::compare($difference, '-'.$tolerance) < 0) {
            throw ValidationException::withMessages([
                "lines.{$index}.total_cost" => __('The supplied line value :total does not match its unit cost :unit for :quantity unit(s).', [
                    'total' => $totalCost,
                    'unit' => $unitCost,
                    'quantity' => $line->quantity,
                ]),
            ]);
        }
    }

    /**
     * @param  array{quantity: string, value: string}  $state
     */
    private function currentAverage(array $state): string
    {
        if (DecimalMath::isZero($state['quantity'])) {
            return DecimalMath::zero(DecimalMath::COST_SCALE);
        }

        return DecimalMath::cost(DecimalMath::div($state['value'], $state['quantity']));
    }

    private function rejectNegative(
        Product $product,
        PendingInventoryLine $line,
        string $available,
        string $field,
        bool $isLot = false,
    ): never {
        $message = $isLot
            ? __('Posting :quantity of :product would take lot stock below zero. Available: :available.', [
                'quantity' => DecimalMath::quantity($line->quantity),
                'product' => $product->code,
                'available' => DecimalMath::quantity($available),
            ])
            : __('Posting :quantity of :product would take stock below zero. Available: :available.', [
                'quantity' => DecimalMath::quantity($line->quantity),
                'product' => $product->code,
                'available' => DecimalMath::quantity($available),
            ]);

        throw ValidationException::withMessages([$field => $message]);
    }
}
