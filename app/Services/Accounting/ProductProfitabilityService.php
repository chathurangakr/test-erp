<?php

namespace App\Services\Accounting;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Operational product profitability — **not** the financial profit and loss statement.
 *
 * The two answer different questions and must never be conflated:
 *
 *   this report      "was this physical sale profitable?"      built from DELIVERIES
 *   the P&L          "what revenue has been recognised?"       built from the GENERAL LEDGER
 *
 * A delivery establishes both figures at once: what the goods were sold for, and what they
 * cost, valued at the weighted average in force when the stock left. The sales invoice may
 * follow later, in parts, or not at all — so this report can show a profitable month while
 * the P&L shows less recognised revenue, and both are correct.
 *
 * ## Both figures are read, never recomputed
 *
 * `sales_value` and `cost_of_goods_sold` are taken exactly as the delivery stored them.
 * `sales_value` already honours the line's `pricing_basis`, so raw shell priced per KG is
 * right without this service knowing anything about kilograms — and re-deriving it would
 * require a NOS-to-KG conversion, which must never exist. Cost follows the primary quantity,
 * because that is what physically left stock.
 *
 * Only POSTED deliveries count. A reversed delivery never happened as far as profit is
 * concerned, and its lines are excluded rather than netted, because a reversal restores the
 * stock outright. See docs/ACCOUNTING_RULES.md §25.
 */
class ProductProfitabilityService
{
    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     sales_total: string,
     *     cogs_total: string,
     *     gross_profit_total: string,
     *     gross_margin_percent: string,
     *     delivery_count: int,
     *     from: string|null,
     *     to: string|null
     * }
     */
    public function report(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?int $productId = null,
        ?int $customerId = null,
    ): array {
        $lines = DeliveryLine::query()
            ->with(['product.primaryUom:id,code', 'product.secondaryUom:id,code'])
            ->whereHas('delivery', function (Builder $delivery) use ($from, $to, $customerId): void {
                // Posted only: a draft moved nothing and a reversal undid everything.
                $delivery->where('status', DeliveryStatus::Posted);

                if ($from !== null) {
                    $delivery->whereOnOrAfter('delivery_date', $from->toDateString());
                }

                if ($to !== null) {
                    $delivery->whereOnOrBefore('delivery_date', $to->toDateString());
                }

                if ($customerId !== null) {
                    $delivery->where('customer_id', $customerId);
                }
            })
            ->when($productId !== null, fn (Builder $query) => $query->where('product_id', $productId))
            ->get();

        $byProduct = [];
        $deliveryIds = [];

        foreach ($lines as $line) {
            $product = $line->product;

            if ($product === null) {
                continue;
            }

            $id = $product->id;
            $deliveryIds[$line->delivery_id] = true;

            if (! isset($byProduct[$id])) {
                $byProduct[$id] = [
                    'product_id' => $id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'primary_uom' => $product->primaryUom?->code,
                    'secondary_uom' => $product->secondaryUom?->code,
                    'quantity' => DecimalMath::zero(),
                    'secondary_quantity' => $product->secondaryUom === null ? null : DecimalMath::zero(),
                    'sales_value' => DecimalMath::zero(),
                    'cost_of_goods_sold' => DecimalMath::zero(),
                    'delivery_ids' => [],
                ];
            }

            $byProduct[$id]['quantity'] = DecimalMath::add(
                $byProduct[$id]['quantity'],
                DecimalMath::of($line->quantity),
            );

            if ($byProduct[$id]['secondary_quantity'] !== null) {
                $byProduct[$id]['secondary_quantity'] = DecimalMath::add(
                    $byProduct[$id]['secondary_quantity'],
                    DecimalMath::of($line->secondary_quantity ?? '0'),
                );
            }

            // Read, never recalculated — see the class docblock.
            $byProduct[$id]['sales_value'] = DecimalMath::add(
                $byProduct[$id]['sales_value'],
                DecimalMath::of($line->sales_value),
            );
            $byProduct[$id]['cost_of_goods_sold'] = DecimalMath::add(
                $byProduct[$id]['cost_of_goods_sold'],
                DecimalMath::of($line->cost_of_goods_sold ?? '0'),
            );

            $byProduct[$id]['delivery_ids'][$line->delivery_id] = true;
        }

        $rows = [];
        $salesTotal = DecimalMath::zero();
        $cogsTotal = DecimalMath::zero();

        foreach ($byProduct as $entry) {
            $sales = DecimalMath::cost($entry['sales_value']);
            $cogs = DecimalMath::cost($entry['cost_of_goods_sold']);
            $profit = DecimalMath::cost(DecimalMath::sub($sales, $cogs));

            $rows[] = [
                'product_id' => $entry['product_id'],
                'product_code' => $entry['product_code'],
                'product_name' => $entry['product_name'],
                'primary_uom' => $entry['primary_uom'],
                'secondary_uom' => $entry['secondary_uom'],
                'quantity' => DecimalMath::quantity($entry['quantity']),
                'secondary_quantity' => $entry['secondary_quantity'] === null
                    ? null
                    : DecimalMath::quantity($entry['secondary_quantity']),
                'sales_value' => $sales,
                'cost_of_goods_sold' => $cogs,
                'gross_profit' => $profit,
                'gross_margin_percent' => $this->marginPercent($profit, $sales),
                'delivery_count' => count($entry['delivery_ids']),
            ];

            $salesTotal = DecimalMath::add($salesTotal, $sales);
            $cogsTotal = DecimalMath::add($cogsTotal, $cogs);
        }

        usort($rows, fn (array $a, array $b): int => DecimalMath::compare($b['sales_value'], $a['sales_value']));

        $salesTotal = DecimalMath::cost($salesTotal);
        $cogsTotal = DecimalMath::cost($cogsTotal);
        $profitTotal = DecimalMath::cost(DecimalMath::sub($salesTotal, $cogsTotal));

        return [
            'rows' => $rows,
            'sales_total' => $salesTotal,
            'cogs_total' => $cogsTotal,
            'gross_profit_total' => $profitTotal,
            'gross_margin_percent' => $this->marginPercent($profitTotal, $salesTotal),
            'delivery_count' => count($deliveryIds),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ];
    }

    /**
     * The deliveries behind one product's figures, for drilling down.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveriesFor(
        int $productId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?int $customerId = null,
    ): array {
        return Delivery::query()
            ->where('status', DeliveryStatus::Posted)
            ->whereHas('lines', fn (Builder $line) => $line->where('product_id', $productId))
            ->when($from, fn (Builder $q, CarbonInterface $d) => $q->whereOnOrAfter('delivery_date', $d->toDateString()))
            ->when($to, fn (Builder $q, CarbonInterface $d) => $q->whereOnOrBefore('delivery_date', $d->toDateString()))
            ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->with(['customer:id,code,name'])
            ->orderByDesc('delivery_date')
            ->limit(50)
            ->get()
            ->map(fn (Delivery $delivery): array => [
                'id' => $delivery->id,
                'delivery_number' => $delivery->delivery_number,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'customer_code' => $delivery->customer?->code,
                'customer_name' => $delivery->customer?->name,
            ])
            ->all();
    }

    /**
     * Margin as a percentage, safe at zero sales.
     *
     * Nothing sold means no margin — zero, never a division error.
     */
    private function marginPercent(string $profit, string $sales): string
    {
        if (DecimalMath::isZero($sales)) {
            return DecimalMath::round('0', 2);
        }

        return DecimalMath::round(DecimalMath::mul(DecimalMath::div($profit, $sales), '100'), 2);
    }
}
