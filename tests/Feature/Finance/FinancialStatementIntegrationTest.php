<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Cash\PostExpenseVoucher;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Payables\PostSupplierPayment;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Enums\JournalEntryStatus;
use App\Enums\SupplierInvoiceLineType;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\ExpenseVoucher;
use App\Models\ExpenseVoucherLine;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashBankBookService;
use App\Services\Accounting\FinanceDashboardService;
use App\Services\Accounting\InventoryGlReconciliationService;
use App\Services\Accounting\InventoryValuationReportService;
use App\Services\Accounting\ProductProfitabilityService;
use App\Services\Accounting\ProfitLossService;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One trading month, driven entirely through the real business documents, then read back
 * through every financial statement and management report.
 *
 * Nothing here posts a journal by hand except the owner's opening capital, which genuinely is
 * a manual entry. Every other figure arrives because a goods receipt, a delivery, an invoice,
 * a receipt, a payment or an expense voucher was posted — which is the only way to prove the
 * modules still agree with each other.
 *
 * The scenario:
 *
 *   capital           Dr Bank              2,000,000   Cr Owner Capital    2,000,000
 *   goods receipt     Dr Inventory         1,025,000   Cr GRNI             1,025,000
 *   supplier invoice  Dr GRNI              1,025,000   Cr Trade Payables   1,025,000
 *   supplier payment  Dr Trade Payables      500,000   Cr Bank               500,000
 *   delivery          Dr Cost of Sales       205,000   Cr Inventory          205,000
 *   sales invoice     Dr Trade Receivables   260,000   Cr Revenue            260,000
 *   customer receipt  Dr Bank                100,000   Cr Trade Receivables  100,000
 *   expense voucher   Dr Electricity          10,000   Cr Bank                10,000
 */
class FinancialStatementIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Customer $customer;

    private Supplier $supplier;

    private Product $coconut;

    private FiscalPeriod $period;

    private CashBankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->customer = Customer::factory()->create(['code' => 'CUS-001', 'credit_days' => 30]);
        $this->supplier = Supplier::factory()->create(['code' => 'SUP-001']);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $nos->id]);
        ProductAccountMapping::create([
            'product_id' => $this->coconut->id,
            'inventory_account_id' => $this->account('1310')->id,
            'cogs_account_id' => $this->account('5110')->id,
            'revenue_account_id' => $this->account('4110')->id,
        ]);

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);

        $this->bank = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();

        app(UpdateAccountingSettings::class)->handle([
            'grni_account_id' => $this->account('2120')->id,
            'trade_payables_account_id' => $this->account('2110')->id,
            'trade_receivables_account_id' => $this->account('1210')->id,
            'operational_accounting_start_date' => '2026-09-01',
        ]);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function balances(): AccountingBalanceService
    {
        return app(AccountingBalanceService::class);
    }

    /**
     * Runs the whole month and returns the delivery, for the reports that need it.
     */
    private function tradeForAMonth(): Delivery
    {
        // 1. The owner puts capital in. A genuine manual journal.
        $capital = JournalEntry::create([
            'journal_number' => 'JV-20260901-000001',
            'journal_date' => '2026-09-01',
            'fiscal_period_id' => $this->period->id,
            'description' => 'Opening capital',
            'status' => JournalEntryStatus::Draft,
        ]);
        $capital->lines()->create(['account_id' => $this->bank->account_id, 'debit' => '2000000.000000', 'credit' => '0.000000']);
        $capital->lines()->create(['account_id' => $this->account('3100')->id, 'debit' => '0.000000', 'credit' => '2000000.000000']);
        app(PostJournalEntry::class)->handle($capital->refresh());

        // 2. Goods in: 10,000 coconut at 100, plus 25,000 of landed cost = 1,025,000.
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-02',
            'transport_cost' => '20000.000000',
            'loading_cost' => '5000.000000',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->coconut->id,
            'quantity' => '10000.000',
            'unit_price' => '100.000000',
        ]);
        $receipt = app(PostGoodsReceipt::class)->handle($receipt->refresh());

        // 3. The supplier invoices for it, clearing the accrual.
        $invoice = SupplierInvoice::factory()->create([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => '2026-09-03',
            'due_date' => '2026-09-30',
        ]);
        $invoice->lines()->create([
            'line_type' => SupplierInvoiceLineType::GrniMatch,
            'goods_receipt_id' => $receipt->id,
            'description' => 'Coconut and transport',
            'amount' => '1025000.000000',
        ]);
        $invoice = app(PostSupplierInvoice::class)->handle($invoice->refresh());

        // 4. Part of it is paid.
        $payment = SupplierPayment::factory()->create([
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-09-04',
            'payment_account_id' => $this->bank->account_id,
            'amount' => '500000.000000',
        ]);
        SupplierPaymentAllocation::factory()->create([
            'supplier_payment_id' => $payment->id,
            'supplier_invoice_id' => $invoice->id,
            'amount' => '500000.000000',
        ]);
        app(PostSupplierPayment::class)->handle($payment);

        // 5. 2,000 coconut go out at 130. Cost follows the weighted average: 102.50.
        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-10',
        ]);
        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->coconut->id,
            'lot_id' => $receipt->lines()->first()->lot_id,
            'quantity' => '2000.000',
            'unit_price' => '130.000000',
        ]);
        $delivery = app(PostDelivery::class)->handle($delivery->refresh());

        // 6. The customer is invoiced — this is where revenue is recognised.
        $salesInvoice = SalesInvoice::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-09-12',
            'due_date' => '2026-09-30',
        ]);
        $salesInvoice->lines()->create([
            'delivery_line_id' => $delivery->lines->first()->id,
            'product_id' => $this->coconut->id,
            'description' => 'Whole coconut',
            'amount' => '260000.000000',
        ]);
        $salesInvoice = app(PostSalesInvoice::class)->handle($salesInvoice->refresh());

        // 7. The customer pays part of it.
        $cashReceipt = CustomerReceipt::factory()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-09-18',
            'receipt_account_id' => $this->bank->account_id,
            'amount' => '100000.000000',
        ]);
        CustomerReceiptAllocation::factory()->create([
            'customer_receipt_id' => $cashReceipt->id,
            'sales_invoice_id' => $salesInvoice->id,
            'amount' => '100000.000000',
        ]);
        app(PostCustomerReceipt::class)->handle($cashReceipt);

        // 8. The electricity bill is paid on the spot.
        $voucher = ExpenseVoucher::factory()->create([
            'cash_bank_account_id' => $this->bank->id,
            'voucher_date' => '2026-09-20',
            'payee_name' => 'Ceylon Electricity Board',
        ]);
        ExpenseVoucherLine::factory()->create([
            'expense_voucher_id' => $voucher->id,
            'expense_account_id' => $this->account('6400')->id,
            'amount' => '10000.000000',
        ]);
        app(PostExpenseVoucher::class)->handle($voucher->refresh());

        return $delivery;
    }

    #[Test]
    public function the_profit_and_loss_reports_the_months_recognised_performance(): void
    {
        $this->tradeForAMonth();

        $statement = app(ProfitLossService::class)->statement(period: $this->period);

        $this->assertSame('260000.000000', $statement['revenue']['total']);
        $this->assertSame('205000.000000', $statement['cost_of_sales']['total']);
        $this->assertSame('55000.000000', $statement['gross_profit']);
        $this->assertSame('10000.000000', $statement['operating_expenses']['total']);
        $this->assertSame('45000.000000', $statement['net_profit']);
        $this->assertSame('21.15', $statement['gross_margin_percent']);
    }

    #[Test]
    public function the_balance_sheet_balances_exactly(): void
    {
        $this->tradeForAMonth();

        $sheet = app(BalanceSheetService::class)->statement(Carbon::parse('2026-09-30'));

        // Bank 2,000,000 − 500,000 + 100,000 − 10,000 = 1,590,000
        // Receivables 260,000 − 100,000                 =   160,000
        // Inventory 1,025,000 − 205,000                 =   820,000
        $this->assertSame('2570000.000000', $sheet['assets']['total']);

        // Payables 1,025,000 − 500,000; GRNI fully cleared.
        $this->assertSame('525000.000000', $sheet['liabilities']['total']);

        $this->assertSame('2000000.000000', $sheet['equity']['total']);
        $this->assertSame('45000.000000', $sheet['current_earnings']);
        $this->assertSame('2045000.000000', $sheet['total_equity']);

        // Assets = Liabilities + Equity + Current Earnings, to the last decimal.
        $this->assertSame('2570000.000000', $sheet['liabilities_and_equity']);
        $this->assertSame('0.000000', $sheet['difference']);
        $this->assertTrue($sheet['balanced']);
    }

    #[Test]
    public function every_control_account_holds_what_its_subsidiary_ledger_says(): void
    {
        $this->tradeForAMonth();

        // Receivables: invoiced 260,000, received 100,000.
        $this->assertSame('160000.000000', $this->balances()->netBalance($this->account('1210')->id));
        $this->assertSame('160000.000000', app(AccountsReceivableService::class)->totalOutstanding());

        // Payables: invoiced 1,025,000, paid 500,000.
        $this->assertSame('525000.000000', $this->balances()->balance($this->account('2110')));
        $this->assertSame('525000.000000', app(AccountsPayableService::class)->totalOutstanding());

        // GRNI: raised by the receipt, cleared in full by the invoice.
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2120')));

        // Cash and bank, from the cash book.
        $this->assertSame('1590000.000000', app(CashBankBookService::class)->combinedBalance());
    }

    #[Test]
    public function the_inventory_ledger_and_the_general_ledger_agree_to_the_last_decimal(): void
    {
        $this->tradeForAMonth();

        $valuation = app(InventoryValuationReportService::class)->report();
        $row = $valuation['rows'][0];

        // 8,000 coconut left, carried at 102.50.
        $this->assertSame('COCONUT', $row['product_code']);
        $this->assertSame('8000.000', $row['quantity']);
        $this->assertSame('102.500000', $row['weighted_average_cost']);
        $this->assertSame('820000.000000', $row['value']);

        $reconciliation = app(InventoryGlReconciliationService::class)->report();
        $inventoryRow = $reconciliation['rows'][0];

        $this->assertSame('1310', $inventoryRow['account_code']);
        $this->assertSame('820000.000000', $inventoryRow['inventory_value']);
        $this->assertSame('820000.000000', $inventoryRow['gl_balance']);
        $this->assertSame('0.000000', $inventoryRow['difference']);
        $this->assertSame('MATCHED', $inventoryRow['status']);
        $this->assertTrue($reconciliation['reconciled']);
    }

    #[Test]
    public function operational_profitability_and_the_financial_statement_answer_different_questions(): void
    {
        $this->tradeForAMonth();

        $operational = app(ProductProfitabilityService::class)->report();
        $financial = app(ProfitLossService::class)->statement(period: $this->period);

        // Here the delivery was invoiced in full, so the two gross profits agree...
        $this->assertSame('55000.000000', $operational['gross_profit_total']);
        $this->assertSame('55000.000000', $financial['gross_profit']);

        // ...but they are not the same figure. The operational one is built from the
        // delivery; the financial one from recognised revenue, which is why the P&L also
        // carries the 10,000 expense the delivery knows nothing about.
        $this->assertSame('45000.000000', $financial['net_profit']);
        $this->assertSame('COCONUT', $operational['rows'][0]['product_code']);
        $this->assertSame('2000.000', $operational['rows'][0]['quantity']);
    }

    #[Test]
    public function an_uninvoiced_delivery_shows_cost_without_revenue_and_still_balances(): void
    {
        $this->tradeForAMonth();

        // A second delivery, never invoiced.
        $lotId = GoodsReceipt::query()->first()->lines()->first()->lot_id;
        $second = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-25',
        ]);
        DeliveryLine::factory()->create([
            'delivery_id' => $second->id,
            'product_id' => $this->coconut->id,
            'lot_id' => $lotId,
            'quantity' => '1000.000',
            'unit_price' => '140.000000',
        ]);
        app(PostDelivery::class)->handle($second->refresh());

        $statement = app(ProfitLossService::class)->statement(period: $this->period);

        // Revenue unchanged; cost of sales up by 1,000 × 102.50. Correct, not a defect —
        // the unbilled-deliveries report exists precisely to surface this.
        $this->assertSame('260000.000000', $statement['revenue']['total']);
        $this->assertSame('307500.000000', $statement['cost_of_sales']['total']);
        $this->assertSame('-47500.000000', $statement['gross_profit']);

        // Operational profitability, meanwhile, counts both physical sales.
        $this->assertSame('400000.000000', app(ProductProfitabilityService::class)->report()['sales_total']);

        $this->assertTrue(app(BalanceSheetService::class)->statement()['balanced']);
    }

    #[Test]
    public function the_trial_balance_still_balances_after_the_whole_month(): void
    {
        $this->tradeForAMonth();

        $trial = $this->balances()->trialBalance();

        $this->assertTrue($trial['balanced']);
        $this->assertSame($trial['debit_total'], $trial['credit_total']);

        // Net balances, not gross movements:
        //   Dr  bank 1,590,000 · receivables 160,000 · inventory 820,000
        //       cost of sales 205,000 · electricity 10,000
        //   Cr  payables 525,000 · capital 2,000,000 · revenue 260,000
        $this->assertSame('2785000.000000', $trial['debit_total']);
    }

    #[Test]
    public function the_dashboard_shows_the_same_month_from_every_angle(): void
    {
        $this->tradeForAMonth();

        $dashboard = app(FinanceDashboardService::class)->summary(period: $this->period);

        $this->assertSame('260000.000000', $dashboard['performance']['revenue']);
        $this->assertSame('205000.000000', $dashboard['performance']['cost_of_sales']);
        $this->assertSame('55000.000000', $dashboard['performance']['gross_profit']);
        $this->assertSame('10000.000000', $dashboard['performance']['operating_expenses']);
        $this->assertSame('45000.000000', $dashboard['performance']['net_profit']);

        $this->assertSame('1590000.000000', $dashboard['position']['cash_and_bank']);
        $this->assertSame('160000.000000', $dashboard['position']['receivables']);
        $this->assertSame('525000.000000', $dashboard['position']['payables']);
        $this->assertSame('820000.000000', $dashboard['position']['inventory_value']);
        $this->assertSame('45000.000000', $dashboard['position']['current_earnings']);

        $this->assertSame('CUS-001', $dashboard['top_customers'][0]['code']);
        $this->assertSame('160000.000000', $dashboard['top_customers'][0]['outstanding']);
        $this->assertSame('SUP-001', $dashboard['top_suppliers'][0]['code']);
        $this->assertSame('525000.000000', $dashboard['top_suppliers'][0]['outstanding']);
    }
}
