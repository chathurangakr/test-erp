<?php

namespace App\Services\Reporting;

use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Services\Accounting\CashBankBookService;
use App\Services\Accounting\FinanceDashboardService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The figures in the weekly update email — read-only.
 *
 * Sales, profit, money owed and stock value come from the finance dashboard, which itself asks
 * the service that owns each figure; cash and bank come from the cash book; production is the
 * week's POSTED runs. Nothing is recalculated here and nothing is stored. See docs/WEEKLY_UPDATE.md.
 */
class WeeklyUpdateReport
{
    public function __construct(
        private readonly FinanceDashboardService $finance,
        private readonly CashBankBookService $cashBank,
    ) {}

    /**
     * The week to report: Monday to Sunday, ending today when today is Sunday, otherwise the
     * last complete week.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function lastWeek(): array
    {
        $today = BusinessClock::today();
        $end = $today->dayOfWeek === CarbonInterface::SUNDAY ? $today : $today->previous(CarbonInterface::SUNDAY);

        return [$end->subDays(6), $end];
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     performance: array<string, string>,
     *     top_products: list<array<string, mixed>>,
     *     cash_bank: array{rows: list<array{name: string, kind: string, balance: string}>, cash_total: string, bank_total: string, combined_total: string},
     *     owed: array{receivable: string, receivable_overdue: string, payable: string, payable_overdue: string},
     *     stock_value: string,
     *     production: list<array{type: string, runs: int, inputs: list<array{code: string, name: string, uom: string, quantity: string}>, outputs: list<array{code: string, name: string, uom: string, quantity: string}>}>
     * }
     */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $summary = $this->finance->summary($from, $to);
        $cash = $this->cashBank->summary($to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'performance' => $summary['performance'],
            'top_products' => $summary['top_products'],
            'cash_bank' => [
                'rows' => array_map(fn (array $row): array => [
                    'name' => $row['display_name'],
                    'kind' => $row['kind_label'],
                    'balance' => $row['balance'],
                ], $cash['rows']),
                'cash_total' => $cash['cash_total'],
                'bank_total' => $cash['bank_total'],
                'combined_total' => $cash['combined_total'],
            ],
            'owed' => [
                'receivable' => $summary['receivables']['total'],
                'receivable_overdue' => $summary['receivables']['overdue'],
                'payable' => $summary['payables']['total'],
                'payable_overdue' => $summary['payables']['overdue'],
            ],
            'stock_value' => $summary['position']['inventory_value'],
            'production' => $this->production($from, $to),
        ];
    }

    /**
     * Posted production runs dated in the window, per type: how many, what went in and what came
     * out. Also used by the Telegram /today command.
     *
     * @return list<array{type: string, runs: int, inputs: list<array{code: string, name: string, uom: string, quantity: string}>, outputs: list<array{code: string, name: string, uom: string, quantity: string}>}>
     */
    public function production(CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = ProductionOrder::query()
            ->where('status', ProductionStatus::Posted)
            ->whereDate('production_date', '>=', $from->toDateString())
            ->whereDate('production_date', '<=', $to->toDateString())
            ->with(['inputs.product.primaryUom', 'outputs.product.primaryUom'])
            ->get();

        $runs = [];

        foreach (ProductionType::cases() as $type) {
            $ofType = $orders->where('production_type', $type);

            if ($ofType->isEmpty()) {
                continue;
            }

            $runs[] = [
                'type' => $type->label(),
                'runs' => $ofType->count(),
                'inputs' => $this->totals($ofType->flatMap->inputs),
                'outputs' => $this->totals($ofType->flatMap->outputs),
            ];
        }

        return $runs;
    }

    /**
     * Quantities summed per product, in each product's own unit (units are never mixed).
     *
     * @param  Collection<int, ProductionInput|ProductionOutput>  $lines
     * @return list<array{code: string, name: string, uom: string, quantity: string}>
     */
    private function totals(Collection $lines): array
    {
        $byProduct = [];

        foreach ($lines as $line) {
            $id = $line->product_id;
            $byProduct[$id] ??= [
                'code' => (string) $line->product?->code,
                'name' => (string) $line->product?->name,
                'uom' => (string) $line->product?->primaryUom?->code,
                'quantity' => DecimalMath::zero(),
            ];
            $byProduct[$id]['quantity'] = DecimalMath::add($byProduct[$id]['quantity'], (string) $line->quantity);
        }

        return array_values(array_map(
            fn (array $row): array => [...$row, 'quantity' => DecimalMath::quantity($row['quantity'])],
            $byProduct,
        ));
    }
}
