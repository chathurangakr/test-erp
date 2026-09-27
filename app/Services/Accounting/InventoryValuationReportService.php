<?php

namespace App\Services\Accounting;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * What the inventory is worth, from the **inventory ledger** rather than the general ledger.
 *
 * This is the authoritative side of the pair. The general ledger records inventory value
 * because a journal was posted; the inventory ledger records it because stock actually moved.
 * They should agree exactly — `InventoryGlReconciliationService` is what checks — and where
 * they do not, this report is the one describing physical reality.
 *
 * ## No as-of date, deliberately
 *
 * `StockQueryService` derives balances from the whole ledger with no date filter, so there is
 * no safe way to ask it what stock was worth last month. Rather than filter the ledger here
 * and risk a valuation that disagrees with every other stock screen, historical valuation is
 * **left unimplemented** and recorded as future work. A wrong figure in a costing report is
 * worse than an absent one. See docs/ACCOUNTING_RULES.md §26.
 */
class InventoryValuationReportService
{
    public function __construct(private readonly StockQueryService $stock) {}

    /**
     * Current valuation, optionally narrowed to one product or warehouse.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     quantity_total: string,
     *     value_total: string,
     *     unmapped_count: int,
     *     as_of: string
     * }
     */
    public function report(?int $productId = null, ?int $warehouseId = null): array
    {
        $balances = $this->stock->balancesByProductAndWarehouse($productId, $warehouseId);

        $products = $this->products($balances->pluck('product_id')->unique()->all());
        $warehouses = Warehouse::query()->get(['id', 'code', 'name'])->keyBy('id');

        $rows = [];
        $quantityTotal = DecimalMath::zero();
        $valueTotal = DecimalMath::zero();
        $unmapped = 0;

        foreach ($balances as $balance) {
            $product = $products->get($balance->product_id);

            if ($product === null) {
                continue;
            }

            // A product that has moved and come back to nothing is not stock on hand.
            if (DecimalMath::isZero($balance->quantity) && DecimalMath::isZero($balance->value)) {
                continue;
            }

            $account = $product->accountMapping?->inventoryAccount;

            if ($account === null) {
                $unmapped++;
            }

            $secondaryUom = $product->secondaryUom;

            $rows[] = [
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'warehouse_id' => $balance->warehouse_id,
                'warehouse_code' => $warehouses->get($balance->warehouse_id)?->code,
                'primary_uom' => $product->primaryUom?->code,
                'quantity' => $balance->quantity,
                // Recorded where the product has one; never converted to or from the primary.
                'secondary_uom' => $secondaryUom?->code,
                'secondary_quantity' => $secondaryUom === null ? null : $balance->secondary_quantity,
                'weighted_average_cost' => $this->unitCost($balance->value, $balance->quantity),
                'value' => $balance->value,
                'inventory_account_id' => $account?->id,
                'inventory_account_code' => $account?->code,
                'inventory_account_name' => $account?->name,
            ];

            $quantityTotal = DecimalMath::add($quantityTotal, $balance->quantity);
            $valueTotal = DecimalMath::add($valueTotal, $balance->value);
        }

        usort($rows, fn (array $a, array $b): int => [$a['product_code'], $a['warehouse_code']]
            <=> [$b['product_code'], $b['warehouse_code']]);

        return [
            'rows' => $rows,
            'quantity_total' => DecimalMath::quantity($quantityTotal),
            'value_total' => DecimalMath::cost($valueTotal),
            'unmapped_count' => $unmapped,
            'as_of' => BusinessClock::todayString(),
        ];
    }

    /**
     * Inventory value grouped by the general-ledger account each product maps to.
     *
     * Several products share one account — all three husk-chip grades post to Husk Chips
     * Inventory — so this is what the reconciliation compares against, never a per-product
     * figure.
     *
     * @return array{
     *     by_account: array<int, array{value: string, products: list<string>}>,
     *     unmapped_value: string,
     *     unmapped_products: list<string>
     * }
     */
    public function valueByInventoryAccount(): array
    {
        $balances = $this->stock->balancesByProductAndWarehouse();
        $products = $this->products($balances->pluck('product_id')->unique()->all());

        $byAccount = [];
        $unmappedValue = DecimalMath::zero();
        $unmappedProducts = [];

        foreach ($balances as $balance) {
            $product = $products->get($balance->product_id);

            if ($product === null) {
                continue;
            }

            $accountId = $product->accountMapping?->inventory_account_id;

            if ($accountId === null) {
                // The QA product is deliberately unmapped; its stock is real but has never
                // reached the ledger. Reported, never hidden.
                if (! DecimalMath::isZero($balance->value)) {
                    $unmappedValue = DecimalMath::add($unmappedValue, $balance->value);
                    $unmappedProducts[$product->code] = $product->code;
                }

                continue;
            }

            if (! isset($byAccount[$accountId])) {
                $byAccount[$accountId] = ['value' => DecimalMath::zero(), 'products' => []];
            }

            $byAccount[$accountId]['value'] = DecimalMath::add($byAccount[$accountId]['value'], $balance->value);
            $byAccount[$accountId]['products'][$product->code] = $product->code;
        }

        foreach ($byAccount as $id => $entry) {
            $byAccount[$id] = [
                'value' => DecimalMath::cost($entry['value']),
                'products' => array_values($entry['products']),
            ];
        }

        return [
            'by_account' => $byAccount,
            'unmapped_value' => DecimalMath::cost($unmappedValue),
            'unmapped_products' => array_values($unmappedProducts),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function products(array $ids): Collection
    {
        return Product::query()
            ->whereIn('id', $ids)
            ->with(['primaryUom:id,code', 'secondaryUom:id,code', 'accountMapping.inventoryAccount:id,code,name'])
            ->get()
            ->keyBy('id');
    }

    /**
     * The weighted average this stock is carried at. Zero quantity holds zero cost.
     */
    private function unitCost(string $value, string $quantity): string
    {
        return DecimalMath::isZero($quantity)
            ? DecimalMath::cost('0')
            : DecimalMath::cost(DecimalMath::div($value, $quantity));
    }
}
