<?php

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashBankBookService;
use App\Services\Accounting\GrniReconciliationService;
use App\Services\Accounting\InventoryGlReconciliationService;
use App\Services\Accounting\InventoryValuationReportService;
use App\Services\Accounting\ProductProfitabilityService;
use App\Services\Accounting\ProfitLossService;
use App\Services\Accounting\SalesBillingReconciliationService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Every report's export as one plain table set, read from the SAME services and the same filters
 * as its screen — so a PDF or Excel file always shows what the screen shows. Nothing is
 * recalculated here; figures are passed through as the services return them and only formatted
 * by the writer. See docs/REPORT_EXPORTS.md.
 *
 * @phpstan-type Column array{label: string, type: 'text'|'money'|'quantity'|'percent'}
 * @phpstan-type Row array{cells: list<string|int|null>, bold?: bool, indent?: int}
 * @phpstan-type Section array{heading: string|null, columns: list<Column>, rows: list<Row>}
 * @phpstan-type Export array{title: string, filename: string, details: list<string>, sections: list<Section>, landscape?: bool}
 */
class ReportExports
{
    /** The reports that can be exported, by the key their export route carries. */
    public const REPORTS = [
        'profit-loss', 'balance-sheet', 'trial-balance', 'general-ledger', 'cash-bank-position',
        'cash-bank-book', 'ar-aging', 'ap-aging', 'unbilled-deliveries', 'grni-reconciliation',
        'inventory-valuation', 'inventory-gl-reconciliation', 'product-profitability', 'stock-on-hand',
    ];

    private const BUCKETS = ['current' => 'Current', 'days_1_30' => '1–30 days', 'days_31_60' => '31–60 days', 'days_61_90' => '61–90 days', 'days_90_plus' => 'Over 90 days', 'total' => 'Total'];

    public function __construct(
        private readonly ProfitLossService $profitLoss,
        private readonly BalanceSheetService $balanceSheet,
        private readonly AccountingBalanceService $balances,
        private readonly CashBankBookService $cashBank,
        private readonly AccountsReceivableService $receivables,
        private readonly AccountsPayableService $payables,
        private readonly SalesBillingReconciliationService $billing,
        private readonly GrniReconciliationService $grni,
        private readonly InventoryValuationReportService $valuation,
        private readonly InventoryGlReconciliationService $inventoryGl,
        private readonly ProductProfitabilityService $profitability,
    ) {}

    /**
     * @return Export
     */
    public function build(string $report, Request $request): array
    {
        return match ($report) {
            'profit-loss' => $this->profitLoss($request),
            'balance-sheet' => $this->balanceSheet($request),
            'trial-balance' => $this->trialBalance($request),
            'general-ledger' => $this->generalLedger($request),
            'cash-bank-position' => $this->cashBankPosition($request),
            'cash-bank-book' => $this->cashBankBook($request),
            'ar-aging' => $this->aging($request, 'ar'),
            'ap-aging' => $this->aging($request, 'ap'),
            'unbilled-deliveries' => $this->unbilled(),
            'grni-reconciliation' => $this->grniReconciliation(),
            'inventory-valuation' => $this->inventory($request, 'Inventory valuation', 'inventory-valuation', true),
            'stock-on-hand' => $this->inventory($request, 'Stock on hand', 'stock-on-hand', false),
            'inventory-gl-reconciliation' => $this->inventoryGlReconciliation(),
            'product-profitability' => $this->productProfitability($request),
            default => throw new InvalidArgumentException("Unknown report {$report}"),
        };
    }

    private function profitLoss(Request $request): array
    {
        $period = $this->period($request);
        $statement = $this->profitLoss->statement($this->date($request, 'from'), $this->date($request, 'to'), $period);
        $money = [['label' => __('Account'), 'type' => 'text'], ['label' => __('Amount'), 'type' => 'money']];

        $rows = [];

        foreach ([['Revenue', 'revenue'], ['Cost of sales', 'cost_of_sales']] as [$label, $key]) {
            $rows = [...$rows, ...$this->tree(__($label), $statement[$key])];
        }

        $rows[] = ['cells' => [__('Gross profit').' ('.__('margin :percent%', ['percent' => $statement['gross_margin_percent']]).')', $statement['gross_profit']], 'bold' => true];
        $rows = [...$rows, ...$this->tree(__('Operating expenses'), $statement['operating_expenses'])];
        $rows[] = ['cells' => [DecimalMath::isNegative($statement['net_profit']) ? __('Net loss') : __('Net profit'), $statement['net_profit']], 'bold' => true];

        return $this->export(__('Profit and loss'), 'profit-and-loss', [
            $this->range($statement['from'], $statement['to'], $statement['period']),
        ], [['heading' => null, 'columns' => $money, 'rows' => $rows]]);
    }

    private function balanceSheet(Request $request): array
    {
        $asOf = $this->date($request, 'as_of');
        $statement = $this->balanceSheet->statement($asOf);
        $money = [['label' => __('Account'), 'type' => 'text'], ['label' => __('Amount'), 'type' => 'money']];

        $rows = $this->tree(__('Assets'), $statement['assets']);
        $rows = [...$rows, ...$this->tree(__('Liabilities'), $statement['liabilities'])];
        $equity = $this->tree(__('Equity'), $statement['equity']);
        array_splice($equity, count($equity) - 1, 0, [['cells' => [__('Current earnings'), $statement['current_earnings']], 'indent' => 1]]);
        $equity[count($equity) - 1]['cells'][1] = $statement['total_equity'];
        $rows = [...$rows, ...$equity];
        $rows[] = ['cells' => [__('Liabilities and equity'), $statement['liabilities_and_equity']], 'bold' => true];

        return $this->export(__('Balance sheet'), 'balance-sheet', [
            __('As at :date', ['date' => $statement['as_of']]),
        ], [['heading' => null, 'columns' => $money, 'rows' => $rows]]);
    }

    private function trialBalance(Request $request): array
    {
        $period = $this->period($request);
        $asOf = $this->date($request, 'as_of');
        $report = $this->balances->trialBalance($asOf === null ? null : Carbon::instance($asOf), $period);

        $rows = array_map(fn (array $row): array => ['cells' => [$row['code'], $row['name'], $row['type_label'], $row['debit'], $row['credit']]], $report['rows']);
        $rows[] = ['cells' => [__('Total'), null, null, $report['debit_total'], $report['credit_total']], 'bold' => true];

        return $this->export(__('Trial balance'), 'trial-balance', [
            $period?->name ?? __('As at :date', ['date' => $asOf?->toDateString() ?? BusinessClock::todayString()]),
        ], [['heading' => null, 'columns' => [
            ['label' => __('Code'), 'type' => 'text'], ['label' => __('Account'), 'type' => 'text'], ['label' => __('Type'), 'type' => 'text'],
            ['label' => __('Debit'), 'type' => 'money'], ['label' => __('Credit'), 'type' => 'money'],
        ], 'rows' => $rows]]);
    }

    private function generalLedger(Request $request): array
    {
        $account = Account::query()->findOrFail($request->integer('account_id'));
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');
        $ledger = $this->balances->generalLedger($account, $from === null ? null : Carbon::instance($from), $to === null ? null : Carbon::instance($to));

        $rows = [['cells' => [null, null, __('Opening balance'), null, null, $ledger['opening_balance']], 'bold' => true]];

        foreach ($ledger['rows'] as $row) {
            $rows[] = ['cells' => [$row['journal_date'], $row['journal_number'], $row['description'], $row['debit'], $row['credit'], $row['running_balance']]];
        }

        $rows[] = ['cells' => [null, null, __('Closing balance'), $ledger['debit_total'], $ledger['credit_total'], $ledger['closing_balance']], 'bold' => true];

        return $this->export(__('General ledger').' — '.$account->code.' '.$account->name, 'general-ledger-'.$account->code, [
            $this->range($from?->toDateString(), $to?->toDateString()),
        ], [['heading' => null, 'columns' => [
            ['label' => __('Date'), 'type' => 'text'], ['label' => __('Journal'), 'type' => 'text'], ['label' => __('Description'), 'type' => 'text'],
            ['label' => __('Debit'), 'type' => 'money'], ['label' => __('Credit'), 'type' => 'money'], ['label' => __('Balance'), 'type' => 'money'],
        ], 'rows' => $rows]], landscape: true);
    }

    private function cashBankPosition(Request $request): array
    {
        $asOf = $this->date($request, 'as_of');
        $summary = $this->cashBank->summary($asOf, activeOnly: ! $request->boolean('show_inactive'));

        $rows = array_map(fn (array $row): array => ['cells' => [$row['account_code'], $row['display_name'], $row['kind_label'], $row['bank_name'], $row['balance']]], $summary['rows']);
        $rows[] = ['cells' => [__('Cash'), null, null, null, $summary['cash_total']], 'bold' => true];
        $rows[] = ['cells' => [__('Bank'), null, null, null, $summary['bank_total']], 'bold' => true];
        $rows[] = ['cells' => [__('Total'), null, null, null, $summary['combined_total']], 'bold' => true];

        return $this->export(__('Cash and bank position'), 'cash-bank-position', [
            __('As at :date', ['date' => $asOf?->toDateString() ?? BusinessClock::todayString()]),
        ], [['heading' => null, 'columns' => [
            ['label' => __('Code'), 'type' => 'text'], ['label' => __('Account'), 'type' => 'text'], ['label' => __('Kind'), 'type' => 'text'],
            ['label' => __('Bank'), 'type' => 'text'], ['label' => __('Balance'), 'type' => 'money'],
        ], 'rows' => $rows]]);
    }

    private function cashBankBook(Request $request): array
    {
        $accounts = CashBankAccount::query()->with('account:id,code')->where('is_active', true)->get()->sortBy(fn (CashBankAccount $c): string => (string) $c->account?->code)->values();
        $selected = $accounts->firstWhere('id', $request->integer('cash_bank_account_id')) ?? $accounts->first()
            ?? throw new InvalidArgumentException('No cash or bank account is configured.');
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');
        $book = $this->cashBank->book($selected, $from, $to);

        $rows = [['cells' => [null, null, null, __('Opening balance'), null, null, $book['opening_balance']], 'bold' => true]];

        foreach ($book['rows'] as $row) {
            $rows[] = ['cells' => [$row['date'], $row['journal_number'], trim($row['source_label'].' '.$row['source_number']), $row['description'], $row['money_in'], $row['money_out'], $row['running_balance']]];
        }

        $rows[] = ['cells' => [null, null, null, __('Closing balance'), $book['money_in_total'], $book['money_out_total'], $book['closing_balance']], 'bold' => true];

        return $this->export(__('Cash and bank book').' — '.$selected->display_name, 'cash-bank-book', [
            $this->range($from?->toDateString(), $to?->toDateString()),
        ], [['heading' => null, 'columns' => [
            ['label' => __('Date'), 'type' => 'text'], ['label' => __('Journal'), 'type' => 'text'], ['label' => __('Document'), 'type' => 'text'],
            ['label' => __('Description'), 'type' => 'text'], ['label' => __('Money in'), 'type' => 'money'], ['label' => __('Money out'), 'type' => 'money'],
            ['label' => __('Balance'), 'type' => 'money'],
        ], 'rows' => $rows]], landscape: true);
    }

    private function aging(Request $request, string $side): array
    {
        $asOf = $this->date($request, 'as_of');
        $report = $side === 'ar' ? $this->receivables->aging($asOf) : $this->payables->aging($asOf);
        $party = $side === 'ar' ? 'customer' : 'supplier';

        $rows = array_map(fn (array $row): array => ['cells' => [
            $row[$party.'_code'], $row[$party.'_name'], ...array_map(fn (string $bucket): string => $row[$bucket], array_keys(self::BUCKETS)),
        ]], $report['rows']);
        $rows[] = ['cells' => [__('Total'), null, ...array_map(fn (string $bucket): string => $report['totals'][$bucket], array_keys(self::BUCKETS))], 'bold' => true];

        $columns = [['label' => __('Code'), 'type' => 'text'], ['label' => $side === 'ar' ? __('Customer') : __('Supplier'), 'type' => 'text']];

        foreach (self::BUCKETS as $label) {
            $columns[] = ['label' => __($label), 'type' => 'money'];
        }

        return $this->export($side === 'ar' ? __('Customers owe us (AR aging)') : __('We owe suppliers (AP aging)'), $side === 'ar' ? 'ar-aging' : 'ap-aging', [
            __('As at :date', ['date' => $report['as_of']]),
        ], [['heading' => null, 'columns' => $columns, 'rows' => $rows]], landscape: true);
    }

    private function unbilled(): array
    {
        $report = $this->billing->report();

        $rows = array_map(fn (array $row): array => ['cells' => [$row['delivery_date'], $row['delivery_number'], $row['customer_name'], $row['sales_value'], $row['billed_amount'], $row['remaining_amount']]], $report['rows']);
        $rows[] = ['cells' => [__('Total'), null, null, $report['value_total'], $report['billed_total'], $report['remaining_total']], 'bold' => true];

        return $this->export(__('Deliveries not yet invoiced'), 'unbilled-deliveries', [__('As at :date', ['date' => BusinessClock::todayString()])], [['heading' => null, 'columns' => [
            ['label' => __('Date'), 'type' => 'text'], ['label' => __('Delivery'), 'type' => 'text'], ['label' => __('Customer'), 'type' => 'text'],
            ['label' => __('Sales value'), 'type' => 'money'], ['label' => __('Invoiced'), 'type' => 'money'], ['label' => __('Not invoiced'), 'type' => 'money'],
        ], 'rows' => $rows]]);
    }

    private function grniReconciliation(): array
    {
        $report = $this->grni->report();

        $rows = array_map(fn (array $row): array => ['cells' => [$row['receipt_date'], $row['grn_number'], $row['supplier_name'], $row['original_accrual'], $row['matched_amount'], $row['remaining_amount']]], $report['rows']);
        $rows[] = ['cells' => [__('Total'), null, null, $report['original_total'], $report['matched_total'], $report['remaining_total']], 'bold' => true];

        return $this->export(__('Goods received not yet invoiced'), 'grni-reconciliation', [__('As at :date', ['date' => BusinessClock::todayString()])], [['heading' => null, 'columns' => [
            ['label' => __('Date'), 'type' => 'text'], ['label' => __('Goods receipt'), 'type' => 'text'], ['label' => __('Supplier'), 'type' => 'text'],
            ['label' => __('Received value'), 'type' => 'money'], ['label' => __('Invoiced'), 'type' => 'money'], ['label' => __('Not invoiced'), 'type' => 'money'],
        ], 'rows' => $rows]]);
    }

    private function inventory(Request $request, string $title, string $filename, bool $withValue): array
    {
        $productId = $request->integer('product_id') ?: null;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $report = $this->valuation->report($productId, $warehouseId);

        $rows = array_map(fn (array $row): array => ['cells' => array_merge(
            [$row['product_code'], $row['product_name'], $row['warehouse_code'], $row['quantity'], $row['primary_uom'], $row['secondary_quantity'], $row['secondary_uom']],
            $withValue ? [$row['weighted_average_cost'], $row['value']] : [],
        )], $report['rows']);

        if ($withValue) {
            $rows[] = ['cells' => [__('Total'), null, null, null, null, null, null, null, $report['value_total']], 'bold' => true];
        }

        $columns = [
            ['label' => __('Code'), 'type' => 'text'], ['label' => __('Product'), 'type' => 'text'], ['label' => __('Warehouse'), 'type' => 'text'],
            ['label' => __('Quantity'), 'type' => 'quantity'], ['label' => __('Unit'), 'type' => 'text'],
            ['label' => __('Second measure'), 'type' => 'quantity'], ['label' => __('Unit'), 'type' => 'text'],
        ];

        if ($withValue) {
            $columns[] = ['label' => __('Average cost'), 'type' => 'money'];
            $columns[] = ['label' => __('Value'), 'type' => 'money'];
        }

        $details = [__('As at :date', ['date' => $report['as_of']])];

        if ($productId !== null) {
            $details[] = __('Product').': '.Product::query()->whereKey($productId)->value('name');
        }

        if ($warehouseId !== null) {
            $details[] = __('Warehouse').': '.Warehouse::query()->whereKey($warehouseId)->value('name');
        }

        return $this->export(__($title), $filename, $details, [['heading' => null, 'columns' => $columns, 'rows' => $rows]], landscape: $withValue);
    }

    private function inventoryGlReconciliation(): array
    {
        $report = $this->inventoryGl->report();

        $rows = array_map(fn (array $row): array => ['cells' => [
            $row['account_code'], $row['account_name'], implode(', ', (array) $row['products']), $row['inventory_value'], $row['gl_balance'], $row['difference'],
        ]], $report['rows']);
        $rows[] = ['cells' => [__('Total'), null, null, $report['inventory_total'], $report['gl_total'], $report['difference_total']], 'bold' => true];

        return $this->export(__('Inventory to ledger reconciliation'), 'inventory-gl-reconciliation', [__('As at :date', ['date' => BusinessClock::todayString()])], [['heading' => null, 'columns' => [
            ['label' => __('Code'), 'type' => 'text'], ['label' => __('Account'), 'type' => 'text'], ['label' => __('Products'), 'type' => 'text'],
            ['label' => __('Stock value'), 'type' => 'money'], ['label' => __('Ledger balance'), 'type' => 'money'], ['label' => __('Difference'), 'type' => 'money'],
        ], 'rows' => $rows]], landscape: true);
    }

    private function productProfitability(Request $request): array
    {
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');
        $productId = $request->integer('product_id') ?: null;
        $customerId = $request->integer('customer_id') ?: null;
        $report = $this->profitability->report($from, $to, $productId, $customerId);

        $rows = array_map(fn (array $row): array => ['cells' => [
            $row['product_code'], $row['product_name'], $row['quantity'], $row['primary_uom'], $row['delivery_count'],
            $row['sales_value'], $row['cost_of_goods_sold'], $row['gross_profit'], $row['gross_margin_percent'],
        ]], $report['rows']);
        $rows[] = ['cells' => [__('Total'), null, null, null, $report['delivery_count'], $report['sales_total'], $report['cogs_total'], $report['gross_profit_total'], $report['gross_margin_percent']], 'bold' => true];

        $details = [$this->range($report['from'], $report['to']), __('Operational: delivered goods, not the financial profit and loss.')];

        if ($customerId !== null) {
            $details[] = __('Customer').': '.Customer::query()->whereKey($customerId)->value('name');
        }

        return $this->export(__('Product profitability'), 'product-profitability', $details, [['heading' => null, 'columns' => [
            ['label' => __('Code'), 'type' => 'text'], ['label' => __('Product'), 'type' => 'text'], ['label' => __('Quantity'), 'type' => 'quantity'],
            ['label' => __('Unit'), 'type' => 'text'], ['label' => __('Deliveries'), 'type' => 'quantity'], ['label' => __('Sales'), 'type' => 'money'],
            ['label' => __('Cost of goods'), 'type' => 'money'], ['label' => __('Gross profit'), 'type' => 'money'], ['label' => __('Margin %'), 'type' => 'percent'],
        ], 'rows' => $rows]], landscape: true);
    }

    /**
     * A statement section (AccountTreeService) as rows: its heading, every account indented by
     * depth (a group shows its total in bold), then the section total.
     *
     * @param  array{rows: list<array<string, mixed>>, total: string}  $section
     * @return list<array{cells: list<string|null>, bold?: bool, indent?: int}>
     */
    private function tree(string $heading, array $section): array
    {
        $rows = [['cells' => [$heading, null], 'bold' => true]];
        $walk = function (array $nodes) use (&$walk, &$rows): void {
            foreach ($nodes as $node) {
                $rows[] = ['cells' => [$node['code'].' '.$node['name'], $node['amount']], 'bold' => $node['is_group'], 'indent' => $node['depth'] + 1];
                $walk($node['children']);
            }
        };
        $walk($section['rows']);
        $rows[] = ['cells' => [__('Total :section', ['section' => mb_strtolower($heading)]), $section['total']], 'bold' => true];

        return $rows;
    }

    /**
     * @param  list<string>  $details
     * @param  list<array{heading: string|null, columns: list<array{label: string, type: string}>, rows: list<array<string, mixed>>}>  $sections
     */
    private function export(string $title, string $filename, array $details, array $sections, bool $landscape = false): array
    {
        return [
            'title' => $title,
            'filename' => $filename.'-'.BusinessClock::todayString(),
            'details' => array_values(array_filter($details)),
            'sections' => $sections,
            'landscape' => $landscape,
        ];
    }

    private function range(?string $from, ?string $to, ?string $period = null): string
    {
        return match (true) {
            $period !== null => $period.($from !== null ? ' ('.$from.' – '.$to.')' : ''),
            $from !== null && $to !== null => __('From :from to :to', ['from' => $from, 'to' => $to]),
            $from !== null => __('From :from', ['from' => $from]),
            $to !== null => __('Up to :to', ['to' => $to]),
            default => __('All dates'),
        };
    }

    private function period(Request $request): ?FiscalPeriod
    {
        $id = $request->integer('fiscal_period_id') ?: null;

        return $id === null ? null : FiscalPeriod::query()->find($id);
    }

    private function date(Request $request, string $key): ?Carbon
    {
        $date = $request->date($key);

        return $date === null ? null : Carbon::parse($date);
    }
}
