<?php

namespace App\Services\Reporting;

use App\Enums\AdvanceStatus;
use App\Enums\CustomerReceiptStatus;
use App\Enums\ExpenseVoucherStatus;
use App\Enums\FixedAssetStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\LoanDocumentStatus;
use App\Enums\PayrollDocumentStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesReturnStatus;
use App\Enums\StockDamageStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\AssetMaintenance;
use App\Models\CustomerAdvance;
use App\Models\CustomerReceipt;
use App\Models\ExpenseVoucherLine;
use App\Models\FixedAsset;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\PayrollLine;
use App\Models\SalaryAdvance;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockDamageLine;
use App\Models\SupplierAdvance;
use App\Models\SupplierPayment;
use App\Services\Accounting\FinanceDashboardService;
use App\Services\Accounting\ProfitLossService;
use App\Services\Loans\LoanBalances;
use App\Services\Sales\CustomerBagBalances;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The figures in the monthly report sent on the 1st — read-only, nothing stored.
 *
 * Everything in the weekly update (WeeklyUpdateReport, for the whole month) plus: the month
 * before for comparison, expenses by account (the profit and loss statement), sales by customer
 * (standing invoices), purchases by supplier (posted goods receipts), money in and out by kind,
 * returns, damaged stock, loans still owed, fixed assets bought and sold, and bags still with
 * customers. The sales, purchases and money figures work for any window (the Telegram /today
 * command asks for one day). Each figure is read from the posted documents or the service that owns it; reversed,
 * cancelled and draft documents count for nothing. See docs/WEEKLY_UPDATE.md.
 */
class MonthlyUpdateReport
{
    private const TOP_N = 5;

    public function __construct(
        private readonly WeeklyUpdateReport $base,
        private readonly FinanceDashboardService $finance,
        private readonly ProfitLossService $profitLoss,
        private readonly LoanBalances $loans,
        private readonly CustomerBagBalances $bags,
    ) {}

    /**
     * The month before today's, in the business calendar.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function lastMonth(): array
    {
        $start = BusinessClock::today()->startOfMonth()->subMonth();

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    /**
     * A "YYYY-MM" month's first and last day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function month(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $previousFrom = CarbonImmutable::parse($from)->subMonthNoOverflow()->startOfMonth();

        return $this->base->build($from, $to) + [
            'previous' => $this->finance->summary($previousFrom, $previousFrom->endOfMonth()->startOfDay())['performance'],
            'expenses' => $this->expenses($from, $to),
            'sales' => $this->sales($from, $to),
            'purchases' => $this->purchases($from, $to),
            'money' => $this->money($from, $to),
            'stock_events' => $this->stockEvents($from, $to),
            'loans_owed' => $this->loansOwed(),
            'assets' => $this->assets($from, $to),
            'bags_out' => DecimalMath::quantity($this->bags->balances()->reduce(fn (string $sum, object $row): string => DecimalMath::add($sum, $row->quantity), DecimalMath::zero())),
        ];
    }

    /**
     * The month's expense accounts, largest first.
     *
     * @return list<array{name: string, amount: string}>
     */
    private function expenses(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];
        $walk = function (array $nodes) use (&$walk, &$rows): void {
            foreach ($nodes as $node) {
                if (! DecimalMath::isZero($node['own_amount'])) {
                    $rows[] = ['name' => $node['name'], 'amount' => DecimalMath::money($node['own_amount'])];
                }

                $walk($node['children']);
            }
        };
        $walk($this->profitLoss->statement($from, $to)['operating_expenses']['rows']);

        usort($rows, fn (array $a, array $b): int => DecimalMath::compare($b['amount'], $a['amount'], 2));

        return $rows;
    }

    /**
     * Invoiced sales — standing invoices dated in the month — in total and by customer.
     *
     * @return array{invoices: int, total: string, customers: list<array{name: string, amount: string}>}
     */
    public function sales(CarbonInterface $from, CarbonInterface $to): array
    {
        $invoices = SalesInvoice::query()
            ->whereIn('status', [SalesInvoiceStatus::Posted, SalesInvoiceStatus::PartiallyPaid, SalesInvoiceStatus::Paid])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->with(['customer:id,name', 'lines'])
            ->get();

        return [
            'invoices' => $invoices->count(),
            'total' => DecimalMath::money($this->total($invoices->map(fn (SalesInvoice $invoice): string => $invoice->total()))),
            'customers' => $this->byParty($invoices->map(fn (SalesInvoice $invoice): array => [(string) $invoice->customer?->name, $invoice->total()])),
        ];
    }

    /**
     * Goods received — posted receipts dated in the month, materials plus transport, loading and
     * other costs — in total and by supplier.
     *
     * @return array{receipts: int, total: string, suppliers: list<array{name: string, amount: string}>}
     */
    public function purchases(CarbonInterface $from, CarbonInterface $to): array
    {
        $receipts = GoodsReceipt::query()
            ->where('status', GoodsReceiptStatus::Posted)
            ->whereBetween('receipt_date', [$from->toDateString(), $to->toDateString()])
            ->with(['supplier:id,name', 'lines'])
            ->get();
        $value = fn (GoodsReceipt $receipt): string => $receipt->lines->reduce(
            fn (string $sum, GoodsReceiptLine $line): string => DecimalMath::add($sum, (string) $line->material_cost),
            DecimalMath::add(DecimalMath::add((string) $receipt->transport_cost, (string) $receipt->loading_cost), (string) $receipt->other_cost),
        );

        return [
            'receipts' => $receipts->count(),
            'total' => DecimalMath::money($this->total($receipts->map($value))),
            'suppliers' => $this->byParty($receipts->map(fn (GoodsReceipt $receipt): array => [(string) $receipt->supplier?->name, $value($receipt)])),
        ];
    }

    /**
     * Money that came in and went out in the month, by kind. Every figure is a posted document's
     * own amount.
     *
     * @return array<string, string>
     */
    public function money(CarbonInterface $from, CarbonInterface $to): array
    {
        $between = [$from->toDateString(), $to->toDateString()];
        $loanPayments = LoanPayment::query()->where('status', LoanDocumentStatus::Posted)->whereBetween('payment_date', $between);

        return [
            'from_customers' => $this->column(CustomerReceipt::query()->where('status', CustomerReceiptStatus::Posted)->whereBetween('receipt_date', $between), 'amount'),
            'to_suppliers' => $this->column(SupplierPayment::query()->where('status', SupplierPaymentStatus::Posted)->whereBetween('payment_date', $between), 'amount'),
            'expenses' => $this->column(ExpenseVoucherLine::query()->whereHas('voucher', fn ($query) => $query->where('status', ExpenseVoucherStatus::Posted)->whereBetween('voucher_date', $between)), 'amount'),
            'salaries' => $this->column(PayrollLine::query()->whereHas('payrollRun', fn ($query) => $query->where('status', PayrollDocumentStatus::Posted)->whereBetween('pay_date', $between)), 'net_pay'),
            'advances' => $this->column(SalaryAdvance::query()->where('status', PayrollDocumentStatus::Posted)->whereBetween('advance_date', $between), 'amount'),
            'loan_payments' => $this->column(clone $loanPayments, 'amount'),
            'loan_interest' => $this->column(clone $loanPayments, 'interest_amount'),
            'maintenance' => $this->column(AssetMaintenance::query()->where('status', LoanDocumentStatus::Posted)->whereBetween('maintenance_date', $between), 'cost'),
            'advances_from_customers' => $this->column(CustomerAdvance::query()->where('status', AdvanceStatus::Posted)->whereBetween('advance_date', $between), 'amount'),
            'advances_to_suppliers' => $this->column(SupplierAdvance::query()->where('status', AdvanceStatus::Posted)->whereBetween('advance_date', $between), 'amount'),
        ];
    }

    /**
     * @return array{returns: int, returns_credited: string, damages: int, damaged_value: string}
     */
    private function stockEvents(CarbonInterface $from, CarbonInterface $to): array
    {
        $between = [$from->toDateString(), $to->toDateString()];
        $returns = SalesReturn::query()->where('status', SalesReturnStatus::Posted)->whereBetween('return_date', $between);
        $damaged = StockDamageLine::query()->whereHas('stockDamage', fn ($query) => $query->where('status', StockDamageStatus::Posted)->whereBetween('damage_date', $between));

        return [
            'returns' => (clone $returns)->count(),
            'returns_credited' => $this->column($returns, 'credit_amount'),
            'damages' => (clone $damaged)->distinct()->count('stock_damage_id'),
            'damaged_value' => $this->column($damaged, 'total_cost'),
        ];
    }

    /** What is still owed on all standing loans now. */
    private function loansOwed(): string
    {
        return DecimalMath::money($this->total(Loan::query()->where('status', LoanDocumentStatus::Posted)->get()
            ->map(fn (Loan $loan): string => $this->loans->outstanding($loan))));
    }

    /**
     * @return array{bought: int, bought_value: string, sold: int, sold_value: string}
     */
    private function assets(CarbonInterface $from, CarbonInterface $to): array
    {
        $between = [$from->toDateString(), $to->toDateString()];
        $bought = FixedAsset::query()->whereIn('status', [FixedAssetStatus::InUse, FixedAssetStatus::Sold])->whereBetween('purchase_date', $between);
        $sold = FixedAsset::query()->where('status', FixedAssetStatus::Sold)->whereBetween('sold_date', $between);

        return [
            'bought' => (clone $bought)->count(),
            'bought_value' => $this->column($bought, 'purchase_price'),
            'sold' => (clone $sold)->count(),
            'sold_value' => $this->column($sold, 'sale_price'),
        ];
    }

    /**
     * Amounts summed per party, largest first, the top few.
     *
     * @param  Collection<int, array{0: string, 1: string}>  $rows
     * @return list<array{name: string, amount: string}>
     */
    private function byParty(Collection $rows): array
    {
        $totals = [];

        foreach ($rows as [$name, $amount]) {
            $totals[$name] = DecimalMath::add($totals[$name] ?? DecimalMath::zero(), $amount);
        }

        uasort($totals, fn (string $a, string $b): int => DecimalMath::compare($b, $a, 6));

        return array_map(
            fn (int|string $name, string $amount): array => ['name' => (string) $name, 'amount' => DecimalMath::money($amount)],
            array_keys(array_slice($totals, 0, self::TOP_N, true)),
            array_slice($totals, 0, self::TOP_N, true),
        );
    }

    /**
     * @param  Collection<int, string>  $amounts
     */
    private function total(Collection $amounts): string
    {
        return $amounts->reduce(fn (string $sum, string $amount): string => DecimalMath::add($sum, $amount), DecimalMath::zero());
    }

    private function column(mixed $query, string $column): string
    {
        return DecimalMath::money(DecimalMath::of((string) ($query->sum($column) ?: '0')));
    }
}
