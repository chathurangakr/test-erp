<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Services\Accounting\CashBankBookService;
use App\Services\Accounting\FinanceDashboardService;
use App\Services\Accounting\InventoryValuationReportService;
use App\Services\Accounting\ProductProfitabilityService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home screen: shortcuts plus a few charts.
 *
 * Each chart is fetched from the report that owns its figures, and only for a user who may open
 * that report — the same permission its own screen requires. A chart someone may not see is sent
 * as null, so its figures never reach their browser.
 */
class DashboardController extends Controller
{
    /** How many products the bar charts list. */
    private const TOP_PRODUCTS = 6;

    /** How many days "recent sales" covers, ending today. */
    private const RECENT_DAYS = 30;

    public function __construct(
        private readonly FinanceDashboardService $finance,
        private readonly ProductProfitabilityService $profitability,
        private readonly CashBankBookService $cashBank,
        private readonly InventoryValuationReportService $inventory,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = BusinessClock::today();

        return Inertia::render('Dashboard', [
            'charts' => [
                'trend' => $user->hasPermission(Permission::ReportsManagement)
                    ? $this->finance->monthlyTrend($today)
                    : null,
                'recent_sales' => $user->hasPermission(Permission::ReportsManagement)
                    ? $this->recentSales($today)
                    : null,
                'cash_bank' => $user->hasPermission(Permission::ReportsFinance)
                    ? $this->cashBankBalances($today)
                    : null,
                'stock_value' => $user->hasPermission(Permission::ReportsInventory)
                    ? $this->stockValue()
                    : null,
            ],
        ]);
    }

    /**
     * The best-selling products over the last 30 days, by delivered sales value.
     *
     * @return array{from: string, to: string, rows: list<array{product_id: int, product_code: string, product_name: string, sales_value: string}>}
     */
    private function recentSales(CarbonInterface $today): array
    {
        $from = $today->copy()->subDays(self::RECENT_DAYS - 1);
        $rows = $this->profitability->report($from, $today)['rows'];

        return [
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'rows' => array_map(fn (array $row): array => [
                'product_id' => $row['product_id'],
                'product_code' => $row['product_code'],
                'product_name' => $row['product_name'],
                'sales_value' => $row['sales_value'],
            ], array_slice($rows, 0, self::TOP_PRODUCTS)),
        ];
    }

    /**
     * Each configured cash and bank account's balance today.
     *
     * @return list<array{id: int, name: string, kind: string, balance: string}>
     */
    private function cashBankBalances(CarbonInterface $today): array
    {
        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['display_name'],
            'kind' => $row['kind_label'],
            'balance' => $row['balance'],
        ], $this->cashBank->summary($today)['rows']);
    }

    /**
     * Current stock value by product (all warehouses together), largest first.
     *
     * @return list<array{product_id: int, product_code: string, product_name: string, value: string}>
     */
    private function stockValue(): array
    {
        $byProduct = [];

        foreach ($this->inventory->report()['rows'] as $row) {
            $id = $row['product_id'];
            $byProduct[$id] ??= [
                'product_id' => $id,
                'product_code' => $row['product_code'],
                'product_name' => $row['product_name'],
                'value' => DecimalMath::zero(),
            ];
            $byProduct[$id]['value'] = DecimalMath::add($byProduct[$id]['value'], $row['value']);
        }

        $rows = array_values($byProduct);
        usort($rows, fn (array $a, array $b): int => DecimalMath::compare($b['value'], $a['value']));

        return array_map(
            fn (array $row): array => [...$row, 'value' => DecimalMath::cost($row['value'])],
            array_slice($rows, 0, self::TOP_PRODUCTS),
        );
    }
}
