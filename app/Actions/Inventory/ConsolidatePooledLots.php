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
use App\Support\RecordText;
use Illuminate\Support\Facades\DB;

/**
 * Moves a pooled product's old lot balances into its lot-free stock — once, on purpose.
 *
 * When a product stops tracking lots (tracks_lots = false), stock it already held in lots
 * stays there. This writes ONE value-neutral LOT_CONSOLIDATION transaction: for each lot
 * balance, the quantity leaves the lot and enters the lot-free pool in the same warehouse.
 * Both legs share one unit cost and one authoritative total, so neither the product's
 * quantity, its value nor its weighted average moves; no journal is needed (same product,
 * same account). Nothing is edited or deleted — the lots keep their full history and simply
 * end at zero.
 *
 * Run it from the console (inventory:consolidate-pooled-lots). Safe to re-run: with nothing
 * left in lots, it writes nothing. See docs/INVENTORY_RULES.md §15.
 */
class ConsolidatePooledLots
{
    public function __construct(
        private readonly PostInventoryTransaction $post,
        private readonly StockQueryService $stock,
    ) {}

    /**
     * What would move: every non-zero lot balance of a pooled product.
     *
     * @return list<array{product: Product, warehouse: Warehouse, lot: InventoryLot, quantity: string}>
     */
    public function plan(): array
    {
        $pooled = Product::query()->where('tracks_lots', false)->get()->keyBy('id');

        if ($pooled->isEmpty()) {
            return [];
        }

        $lots = InventoryLot::query()->whereIn('product_id', $pooled->keys())->get()->keyBy('id');
        $warehouses = Warehouse::query()->get()->keyBy('id');
        $plan = [];

        foreach ($this->stock->balancesByLot() as $row) {
            $lot = $lots->get($row->lot_id);

            if ($lot === null || DecimalMath::isZero($row->quantity)) {
                continue;
            }

            $plan[] = [
                'product' => $pooled->get($lot->product_id),
                'warehouse' => $warehouses->get($row->warehouse_id),
                'lot' => $lot,
                'quantity' => $row->quantity,
            ];
        }

        usort($plan, fn (array $a, array $b): int => [$a['product']->code, $a['lot']->id] <=> [$b['product']->code, $b['lot']->id]);

        return $plan;
    }

    public function handle(?int $userId = null): ?InventoryTransaction
    {
        return DB::transaction(function () use ($userId): ?InventoryTransaction {
            $plan = $this->plan();

            if ($plan === []) {
                return null;
            }

            $lines = [];

            foreach ($plan as $move) {
                $productId = $move['product']->id;
                $positive = DecimalMath::isPositive($move['quantity']);
                $quantity = $positive ? $move['quantity'] : DecimalMath::mul($move['quantity'], '-1');

                // Value-neutral: both legs carry the same figures, so the product's total
                // quantity and value — and therefore its average — never move.
                $unitCost = $this->stock->weightedAverageCostForProduct($productId);
                $totalCost = $this->stock->issueValue(
                    $quantity,
                    $this->stock->quantityForProduct($productId),
                    $this->stock->valueForProduct($productId),
                );

                // A positive lot balance leaves the lot for the pool; a negative one (issued
                // without its lot) is made good from the pool, so the lot ends at zero.
                $lotLeg = $positive ? InventoryDirection::Out : InventoryDirection::In;
                $poolLeg = $positive ? InventoryDirection::In : InventoryDirection::Out;

                foreach ([[$lotLeg, $move['lot']->id], [$poolLeg, null]] as [$direction, $lotId]) {
                    $lines[] = (new PendingInventoryLine(
                        productId: $productId,
                        warehouseId: $move['warehouse']->id,
                        direction: $direction,
                        quantity: DecimalMath::quantity($quantity),
                        lotId: $lotId,
                    ))->withAuthoritativeCost($unitCost, $totalCost);
                }
            }

            return $this->post->handle(InventoryTransactionType::LotConsolidation, $lines, [
                'transaction_date' => BusinessClock::todayString(),
                'notes' => RecordText::of('Old lot balances moved into lot-free pooled stock'),
                'posted_by' => $userId,
            ]);
        });
    }
}
