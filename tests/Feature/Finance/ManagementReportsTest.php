<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\ReverseDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\JournalEntryStatus;
use App\Enums\PricingBasis;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
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
 * Inventory valuation, inventory-to-general-ledger reconciliation, the cash position and
 * operational product profitability.
 *
 * The distinction these tests protect: inventory valuation and product profitability come
 * from the **operational** ledgers, while the cash position and the reconciliation's right
 * hand come from the **general** ledger. Where the two disagree the report says so rather
 * than quietly reconciling them.
 */
class ManagementReportsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Customer $customer;

    private FiscalPeriod $period;

    /** @var array<string, Product> */
    private array $products = [];

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->customer = Customer::factory()->create(['code' => 'CUS-001']);

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);

        // Whole coconut, and raw shell — the dual-unit product that keeps the report honest.
        $this->products['COCONUT'] = $this->product('COCONUT', $this->nos, null, '1310', '5110', '4110');
        $this->products['RAW-SHELL'] = $this->product('RAW-SHELL', $this->nos, $this->kg, '1360', '5150', '4150');
        // Two grades sharing one inventory account, so grouping is exercised.
        $this->products['HUSK-7C'] = $this->product('HUSK-7C', $this->kg, null, '1340', '5130', '4130');
        $this->products['HUSK-9C'] = $this->product('HUSK-9C', $this->kg, null, '1340', '5130', '4130');
    }

    private function product(string $code, UnitOfMeasure $primary, ?UnitOfMeasure $secondary, string $inventory, string $cogs, string $revenue): Product
    {
        $product = Product::factory()->create([
            'code' => $code,
            'primary_uom_id' => $primary->id,
            'secondary_uom_id' => $secondary?->id,
        ]);

        ProductAccountMapping::create([
            'product_id' => $product->id,
            'inventory_account_id' => $this->account($inventory)->id,
            'cogs_account_id' => $this->account($cogs)->id,
            'revenue_account_id' => $this->account($revenue)->id,
        ]);

        return $product;
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function enableAccounting(): void
    {
        app(UpdateAccountingSettings::class)->handle([
            'grni_account_id' => $this->account('2120')->id,
            'trade_payables_account_id' => $this->account('2110')->id,
            'trade_receivables_account_id' => $this->account('1210')->id,
            'operational_accounting_start_date' => '2026-09-01',
        ]);
    }

    /**
     * Receive stock through a goods receipt — the only route that also writes a journal.
     *
     * Posting straight through the inventory engine moves stock and journals nothing, which
     * is by design: business documents drive the ledger. Reconciliation tests therefore have
     * to buy the stock the way the factory does.
     */
    private function purchase(Product $product, string $quantity, string $unitCost): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-02',
            'transport_cost' => '0.000000',
            'loading_cost' => '0.000000',
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitCost,
        ]);

        return app(PostGoodsReceipt::class)->handle($receipt->refresh());
    }

    /** The lot a posted goods receipt created for a product. */
    private function lotFrom(GoodsReceipt $receipt, Product $product): int
    {
        return $receipt->lines()->where('product_id', $product->id)->firstOrFail()->lot_id;
    }

    /** Receive stock straight through the inventory engine — no journal, as pre-cutover. */
    private function receive(Product $product, string $quantity, string $unitCost, ?string $secondary = null): int
    {
        $lot = app(CreateInventoryLot::class)->handle($product);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $product->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot->id,
                unitCost: $unitCost,
                secondaryQuantity: $secondary,
            ),
        ], ['transaction_date' => Carbon::parse('2026-09-02')]);

        return $lot->id;
    }

    /**
     * @param  list<array{product: Product, lot: int, quantity: string, price: string, basis?: PricingBasis, secondary?: string}>  $lines
     */
    private function deliver(array $lines, string $date = '2026-09-10'): Delivery
    {
        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => $date,
        ]);

        foreach ($lines as $line) {
            DeliveryLine::factory()->create([
                'delivery_id' => $delivery->id,
                'product_id' => $line['product']->id,
                'lot_id' => $line['lot'],
                'quantity' => $line['quantity'],
                'secondary_quantity' => $line['secondary'] ?? null,
                'pricing_basis' => $line['basis'] ?? PricingBasis::Primary,
                'unit_price' => $line['price'],
            ]);
        }

        return app(PostDelivery::class)->handle($delivery->refresh());
    }

    private function valuation(): InventoryValuationReportService
    {
        return app(InventoryValuationReportService::class);
    }

    private function reconciliation(): InventoryGlReconciliationService
    {
        return app(InventoryGlReconciliationService::class);
    }

    private function profitability(): ProductProfitabilityService
    {
        return app(ProductProfitabilityService::class);
    }

    private function rowFor(array $rows, string $code, string $key = 'product_code'): ?array
    {
        foreach ($rows as $row) {
            if ($row[$key] === $code) {
                return $row;
            }
        }

        return null;
    }

    // ------------------------------------------------------ inventory valuation

    #[Test]
    public function inventory_valuation_reports_quantity_weighted_average_and_value(): void
    {
        $this->receive($this->products['COCONUT'], '5000.000', '102.500000');

        $report = $this->valuation()->report();
        $row = $this->rowFor($report['rows'], 'COCONUT');

        $this->assertSame('5000.000', $row['quantity']);
        $this->assertSame('102.500000', $row['weighted_average_cost']);
        $this->assertSame('512500.000000', $row['value']);
        $this->assertSame('NOS', $row['primary_uom']);
        $this->assertSame('MAIN', $row['warehouse_code']);
        $this->assertSame('512500.000000', $report['value_total']);
    }

    #[Test]
    public function inventory_valuation_names_the_mapped_general_ledger_account(): void
    {
        $this->receive($this->products['COCONUT'], '1000.000', '100.000000');

        $row = $this->rowFor($this->valuation()->report()['rows'], 'COCONUT');

        $this->assertSame('1310', $row['inventory_account_code']);
        $this->assertSame('Whole Coconut Inventory', $row['inventory_account_name']);
        $this->assertSame(0, $this->valuation()->report()['unmapped_count']);
    }

    #[Test]
    public function raw_shell_shows_both_its_units_without_converting_between_them(): void
    {
        // 500 shells weighing 1,750 KG. The two are recorded, never derived from each other.
        $this->receive($this->products['RAW-SHELL'], '500.000', '20.000000', secondary: '1750.000');

        $row = $this->rowFor($this->valuation()->report()['rows'], 'RAW-SHELL');

        $this->assertSame('500.000', $row['quantity']);
        $this->assertSame('NOS', $row['primary_uom']);
        $this->assertSame('1750.000', $row['secondary_quantity']);
        $this->assertSame('KG', $row['secondary_uom']);

        // Value and the weighted average follow the PRIMARY quantity only.
        $this->assertSame('10000.000000', $row['value']);
        $this->assertSame('20.000000', $row['weighted_average_cost']);

        // A product with no secondary unit reports none, rather than a misleading zero.
        $this->receive($this->products['COCONUT'], '100.000', '50.000000');
        $coconut = $this->rowFor($this->valuation()->report()['rows'], 'COCONUT');
        $this->assertNull($coconut['secondary_quantity']);
        $this->assertNull($coconut['secondary_uom']);
    }

    #[Test]
    public function inventory_valuation_filters_by_product_and_warehouse(): void
    {
        $this->receive($this->products['COCONUT'], '1000.000', '100.000000');
        $this->receive($this->products['HUSK-7C'], '200.000', '30.000000');

        $this->assertCount(2, $this->valuation()->report()['rows']);
        $this->assertCount(1, $this->valuation()->report($this->products['COCONUT']->id)['rows']);
        $this->assertCount(2, $this->valuation()->report(null, $this->main->id)['rows']);

        $other = Warehouse::factory()->create(['code' => 'STORE2']);
        $this->assertCount(0, $this->valuation()->report(null, $other->id)['rows']);
    }

    #[Test]
    public function an_empty_inventory_valuation_reports_zero(): void
    {
        $report = $this->valuation()->report();

        $this->assertSame([], $report['rows']);
        $this->assertSame('0.000000', $report['value_total']);
        $this->assertSame('0.000', $report['quantity_total']);
    }

    // ------------------------------------------------- inventory to general ledger

    #[Test]
    public function inventory_and_the_general_ledger_match_exactly_after_accounted_operations(): void
    {
        $this->enableAccounting();
        $receipt = $this->purchase($this->products['COCONUT'], '5000.000', '102.500000');
        $lot = $this->lotFrom($receipt, $this->products['COCONUT']);
        $this->deliver([['product' => $this->products['COCONUT'], 'lot' => $lot, 'quantity' => '2000.000', 'price' => '130.000000']]);

        $report = $this->reconciliation()->report();
        $row = $this->rowFor($report['rows'], '1310', 'account_code');

        // 5,000 × 102.50 received, 2,000 × 102.50 issued.
        $this->assertSame('307500.000000', $row['inventory_value']);
        $this->assertSame('307500.000000', $row['gl_balance']);
        $this->assertSame('0.000000', $row['difference']);
        $this->assertSame('MATCHED', $row['status']);
        $this->assertTrue($report['reconciled']);
        $this->assertSame('0.000000', $report['difference_total']);
    }

    #[Test]
    public function several_products_sharing_one_inventory_account_are_aggregated(): void
    {
        $this->enableAccounting();
        $this->purchase($this->products['HUSK-7C'], '300.000', '40.000000');
        $this->purchase($this->products['HUSK-9C'], '200.000', '25.000000');

        $row = $this->rowFor($this->reconciliation()->report()['rows'], '1340', 'account_code');

        // 12,000 + 5,000, under one account, counted once each.
        $this->assertSame('17000.000000', $row['inventory_value']);
        $this->assertSame('17000.000000', $row['gl_balance']);
        $this->assertSame('MATCHED', $row['status']);
        $this->assertEqualsCanonicalizing(['HUSK-7C', 'HUSK-9C'], $row['products']);
    }

    #[Test]
    public function a_difference_is_detected_and_reported_rather_than_hidden(): void
    {
        $this->enableAccounting();
        $this->purchase($this->products['COCONUT'], '1000.000', '100.000000');

        // Something reaches the inventory account that stock did not put there.
        $entry = JournalEntry::create([
            'journal_number' => 'JV-DRIFT',
            'journal_date' => '2026-09-15',
            'fiscal_period_id' => $this->period->id,
            'source_type' => 'Adjustment',
            'description' => 'Stray posting',
            'status' => JournalEntryStatus::Draft,
        ]);
        $entry->lines()->create(['account_id' => $this->account('1310')->id, 'debit' => '7500.000000', 'credit' => '0.000000']);
        $entry->lines()->create(['account_id' => $this->account('3100')->id, 'debit' => '0.000000', 'credit' => '7500.000000']);
        app(PostJournalEntry::class)->handle($entry->refresh());

        $report = $this->reconciliation()->report();
        $row = $this->rowFor($report['rows'], '1310', 'account_code');

        $this->assertSame('100000.000000', $row['inventory_value']);
        $this->assertSame('107500.000000', $row['gl_balance']);
        $this->assertSame('-7500.000000', $row['difference']);
        $this->assertSame('DIFFERENCE', $row['status']);
        $this->assertFalse($report['reconciled']);
    }

    #[Test]
    public function pre_cutover_stock_shows_an_honest_difference_and_is_not_backfilled(): void
    {
        // No cutover configured: stock moves, nothing journals. Real inventory, no ledger.
        $this->receive($this->products['COCONUT'], '5000.000', '102.500000');

        $report = $this->reconciliation()->report();
        $row = $this->rowFor($report['rows'], '1310', 'account_code');

        $this->assertSame('512500.000000', $row['inventory_value']);
        $this->assertSame('0.000000', $row['gl_balance']);
        $this->assertSame('512500.000000', $row['difference']);
        $this->assertSame('DIFFERENCE', $row['status']);
        $this->assertFalse($report['reconciled']);

        // The report supplies the context needed to judge the difference...
        $this->assertNull($report['cutover_date']);
        $this->assertFalse($report['accounting_enabled']);

        // ...and nothing was invented to make it go away.
        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function stock_held_by_an_unmapped_product_is_reported_separately(): void
    {
        $qa = Product::factory()->create(['code' => 'QA-COCO', 'primary_uom_id' => $this->nos->id]);
        $this->receive($qa, '100.000', '90.000000');

        $report = $this->reconciliation()->report();

        // Real value, no ledger account to compare against — stated, never absorbed into
        // someone else's account.
        $this->assertSame('9000.000000', $report['unmapped_value']);
        $this->assertSame(['QA-COCO'], $report['unmapped_products']);
        $this->assertFalse($report['reconciled']);
    }

    #[Test]
    public function an_empty_reconciliation_reports_nothing_and_reconciles(): void
    {
        $report = $this->reconciliation()->report();

        $this->assertSame([], $report['rows']);
        $this->assertSame('0.000000', $report['difference_total']);
        $this->assertTrue($report['reconciled']);
    }

    // --------------------------------------------------------- cash and bank position

    #[Test]
    public function the_cash_and_bank_position_agrees_with_the_general_ledger(): void
    {
        $cash = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $bank = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();

        foreach ([[$cash, '120000.000000'], [$bank, '880000.000000']] as [$target, $amount]) {
            $entry = JournalEntry::create([
                'journal_number' => 'JV-'.$target->id,
                'journal_date' => '2026-09-03',
                'fiscal_period_id' => $this->period->id,
                'description' => 'Opening funds',
                'status' => JournalEntryStatus::Draft,
            ]);
            $entry->lines()->create(['account_id' => $target->account_id, 'debit' => $amount, 'credit' => '0.000000']);
            $entry->lines()->create(['account_id' => $this->account('3100')->id, 'debit' => '0.000000', 'credit' => $amount]);
            app(PostJournalEntry::class)->handle($entry->refresh());
        }

        $summary = app(CashBankBookService::class)->summary();
        $balances = app(AccountingBalanceService::class);

        $this->assertSame('120000.000000', $summary['cash_total']);
        $this->assertSame('880000.000000', $summary['bank_total']);
        $this->assertSame('1000000.000000', $summary['combined_total']);

        // Each row equals its own ledger account, not just the total.
        foreach ($summary['rows'] as $row) {
            $this->assertSame(
                $balances->balance(Account::findOrFail($row['account_id'])),
                $row['balance'],
            );
        }
    }

    #[Test]
    public function an_empty_cash_position_reports_zero(): void
    {
        $summary = app(CashBankBookService::class)->summary();

        $this->assertSame('0.000000', $summary['cash_total']);
        $this->assertSame('0.000000', $summary['bank_total']);
        $this->assertSame('0.000000', $summary['combined_total']);
        $this->assertCount(2, $summary['rows']);
    }

    // ------------------------------------------- operational product profitability

    #[Test]
    public function product_profitability_uses_the_values_the_delivery_stored(): void
    {
        $lot = $this->receive($this->products['COCONUT'], '5000.000', '102.500000');
        $this->deliver([['product' => $this->products['COCONUT'], 'lot' => $lot, 'quantity' => '2000.000', 'price' => '130.000000']]);

        $report = $this->profitability()->report();
        $row = $this->rowFor($report['rows'], 'COCONUT');

        // 2,000 × 130 sold, 2,000 × 102.50 cost.
        $this->assertSame('2000.000', $row['quantity']);
        $this->assertSame('260000.000000', $row['sales_value']);
        $this->assertSame('205000.000000', $row['cost_of_goods_sold']);
        $this->assertSame('55000.000000', $row['gross_profit']);
        $this->assertSame('21.15', $row['gross_margin_percent']);
        $this->assertSame(1, $row['delivery_count']);
    }

    #[Test]
    public function raw_shell_profitability_uses_the_stored_sales_value_for_a_kg_priced_line(): void
    {
        $lot = $this->receive($this->products['RAW-SHELL'], '1000.000', '20.000000', secondary: '3500.000');

        // Priced per KG: 1,750 KG at 80. The sales value is whatever the delivery computed;
        // this report never multiplies quantity by price itself.
        $this->deliver([[
            'product' => $this->products['RAW-SHELL'],
            'lot' => $lot,
            'quantity' => '500.000',
            'secondary' => '1750.000',
            'basis' => PricingBasis::Secondary,
            'price' => '80.000000',
        ]]);

        $line = DeliveryLine::where('product_id', $this->products['RAW-SHELL']->id)->firstOrFail();
        $row = $this->rowFor($this->profitability()->report()['rows'], 'RAW-SHELL');

        $this->assertSame('140000.000000', $line->sales_value);
        $this->assertSame($line->sales_value, $row['sales_value']);
        // Cost follows the PRIMARY quantity — 500 × 20 — because that is what left stock.
        $this->assertSame('10000.000000', $row['cost_of_goods_sold']);
        $this->assertSame('130000.000000', $row['gross_profit']);

        // Both units are reported; neither was derived from the other.
        $this->assertSame('500.000', $row['quantity']);
        $this->assertSame('1750.000', $row['secondary_quantity']);
        $this->assertSame('NOS', $row['primary_uom']);
        $this->assertSame('KG', $row['secondary_uom']);
    }

    #[Test]
    public function a_zero_sales_value_yields_a_zero_margin_and_never_a_division_error(): void
    {
        $lot = $this->receive($this->products['COCONUT'], '1000.000', '100.000000');
        $this->deliver([['product' => $this->products['COCONUT'], 'lot' => $lot, 'quantity' => '100.000', 'price' => '0.000000']]);

        $row = $this->rowFor($this->profitability()->report()['rows'], 'COCONUT');

        $this->assertSame('0.000000', $row['sales_value']);
        $this->assertSame('10000.000000', $row['cost_of_goods_sold']);
        $this->assertSame('-10000.000000', $row['gross_profit']);
        $this->assertSame('0.00', $row['gross_margin_percent']);
        $this->assertSame('0.00', $this->profitability()->report()['gross_margin_percent']);
    }

    #[Test]
    public function a_reversed_delivery_leaves_product_profitability(): void
    {
        $lot = $this->receive($this->products['COCONUT'], '5000.000', '102.500000');
        $delivery = $this->deliver([['product' => $this->products['COCONUT'], 'lot' => $lot, 'quantity' => '2000.000', 'price' => '130.000000']]);

        $this->assertSame('260000.000000', $this->profitability()->report()['sales_total']);

        app(ReverseDelivery::class)->handle($delivery);

        // The goods came back, so the sale did not happen — excluded, not netted to zero
        // against itself, because a reversal restores the stock outright.
        $report = $this->profitability()->report();
        $this->assertSame([], $report['rows']);
        $this->assertSame('0.000000', $report['sales_total']);
        $this->assertSame(0, $report['delivery_count']);
    }

    #[Test]
    public function product_profitability_filters_by_date_product_and_customer(): void
    {
        $lot = $this->receive($this->products['COCONUT'], '10000.000', '100.000000');
        $shellLot = $this->receive($this->products['RAW-SHELL'], '1000.000', '20.000000', secondary: '3500.000');

        $this->deliver([['product' => $this->products['COCONUT'], 'lot' => $lot, 'quantity' => '1000.000', 'price' => '150.000000']], '2026-09-05');
        $this->deliver([['product' => $this->products['RAW-SHELL'], 'lot' => $shellLot, 'quantity' => '200.000', 'price' => '30.000000']], '2026-09-20');

        $early = $this->profitability()->report(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-10'));
        $this->assertCount(1, $early['rows']);
        $this->assertSame('150000.000000', $early['sales_total']);

        $byProduct = $this->profitability()->report(null, null, $this->products['RAW-SHELL']->id);
        $this->assertCount(1, $byProduct['rows']);
        $this->assertSame('6000.000000', $byProduct['sales_total']);

        $other = Customer::factory()->create(['code' => 'CUS-002']);
        $this->assertSame([], $this->profitability()->report(null, null, null, $other->id)['rows']);
        $this->assertCount(2, $this->profitability()->report(null, null, null, $this->customer->id)['rows']);
    }

    #[Test]
    public function an_empty_product_profitability_report_is_safe(): void
    {
        $report = $this->profitability()->report();

        $this->assertSame([], $report['rows']);
        $this->assertSame('0.000000', $report['sales_total']);
        $this->assertSame('0.000000', $report['gross_profit_total']);
        $this->assertSame('0.00', $report['gross_margin_percent']);
    }

    // ---------------------------------------------------------------- dashboard

    #[Test]
    public function the_dashboard_reports_the_same_figures_as_the_services_behind_it(): void
    {
        $this->enableAccounting();
        $receipt = $this->purchase($this->products['COCONUT'], '5000.000', '102.500000');
        $lot = $this->lotFrom($receipt, $this->products['COCONUT']);
        $this->deliver([['product' => $this->products['COCONUT'], 'lot' => $lot, 'quantity' => '2000.000', 'price' => '130.000000']]);

        $dashboard = app(FinanceDashboardService::class)->summary(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
        );

        $profitLoss = app(ProfitLossService::class)
            ->statement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        // Never recalculated — the dashboard asks the service that owns each figure.
        $this->assertSame($profitLoss['revenue']['total'], $dashboard['performance']['revenue']);
        $this->assertSame($profitLoss['cost_of_sales']['total'], $dashboard['performance']['cost_of_sales']);
        $this->assertSame($profitLoss['gross_profit'], $dashboard['performance']['gross_profit']);
        $this->assertSame($profitLoss['operating_expenses']['total'], $dashboard['performance']['operating_expenses']);
        $this->assertSame($profitLoss['net_profit'], $dashboard['performance']['net_profit']);

        $this->assertSame(
            app(CashBankBookService::class)->summary(Carbon::parse('2026-09-30'))['combined_total'],
            $dashboard['position']['cash_and_bank'],
        );
        $this->assertSame($this->valuation()->report()['value_total'], $dashboard['position']['inventory_value']);

        // A delivery raises cost of sales and no revenue until it is invoiced.
        $this->assertSame('0.000000', $dashboard['performance']['revenue']);
        $this->assertSame('205000.000000', $dashboard['performance']['cost_of_sales']);
        $this->assertSame('-205000.000000', $dashboard['performance']['net_profit']);

        // Operational profitability tells the other half of the story.
        $this->assertSame('COCONUT', $dashboard['top_products'][0]['product_code']);
        $this->assertSame('55000.000000', $dashboard['top_products'][0]['gross_profit']);
    }

    #[Test]
    public function the_dashboard_trend_reads_each_month_from_the_profit_and_loss_statement(): void
    {
        $this->enableAccounting();
        $receipt = $this->purchase($this->products['COCONUT'], '5000.000', '102.500000');
        $lot = $this->lotFrom($receipt, $this->products['COCONUT']);
        $this->deliver([['product' => $this->products['COCONUT'], 'lot' => $lot, 'quantity' => '2000.000', 'price' => '130.000000']]);

        $trend = app(FinanceDashboardService::class)->monthlyTrend(Carbon::parse('2026-09-30'));

        $this->assertSame(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'], array_column($trend, 'month'));

        $september = app(ProfitLossService::class)->statement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $this->assertSame('2026-09-30', $trend[5]['to']);
        $this->assertSame($september['revenue']['total'], $trend[5]['revenue']);
        $this->assertSame($september['net_profit'], $trend[5]['net_profit']);
        // Costs are cost of sales plus operating expenses.
        $this->assertSame('205000.000000', $trend[5]['costs']);
        $this->assertSame('0.000000', $trend[0]['costs']);
    }

    #[Test]
    public function an_empty_dashboard_reports_zero_everywhere(): void
    {
        $dashboard = app(FinanceDashboardService::class)->summary();

        foreach (['revenue', 'cost_of_sales', 'gross_profit', 'operating_expenses', 'net_profit'] as $key) {
            $this->assertSame('0.000000', $dashboard['performance'][$key]);
        }

        foreach (['cash_and_bank', 'receivables', 'payables', 'inventory_value', 'current_earnings'] as $key) {
            $this->assertSame('0.000000', $dashboard['position'][$key]);
        }

        $this->assertSame([], $dashboard['top_products']);
        $this->assertSame([], $dashboard['top_customers']);
        $this->assertSame([], $dashboard['top_suppliers']);
        $this->assertSame('0.000000', $dashboard['receivables']['overdue']);
    }

    #[Test]
    public function the_dashboards_default_window_prefers_an_open_fiscal_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $window = app(FinanceDashboardService::class)->defaultWindow();

        $this->assertSame('2026-09-01', $window['from']);
        $this->assertSame('2026-09-30', $window['to']);
        $this->assertSame($this->period->id, $window['fiscal_period_id']);

        // With no period covering the date, the screen falls back to a plain month — a UI
        // convenience only, with no accounting meaning.
        Carbon::setTestNow(Carbon::parse('2027-03-15'));
        $fallback = app(FinanceDashboardService::class)->defaultWindow();
        $this->assertSame('2027-03-01', $fallback['from']);
        $this->assertNull($fallback['fiscal_period_id']);

        Carbon::setTestNow();
    }
}
