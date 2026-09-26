<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\ExpenseVoucherStatus;
use App\Enums\FundTransferStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\JournalEntryStatus;
use App\Enums\Permission;
use App\Enums\SupplierInvoiceLineType;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\ExpenseVoucher;
use App\Models\FiscalPeriod;
use App\Models\FundTransfer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashBankBookService;
use App\Services\Accounting\FinanceDashboardService;
use App\Services\Accounting\InventoryGlReconciliationService;
use App\Services\Accounting\ProductProfitabilityService;
use App\Services\Accounting\ProfitLossService;
use App\Support\DecimalMath;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cash and bank documents, and the finance reports, over the API.
 *
 * The documents go through the same Save/Post/Reverse actions as the website. Each report
 * endpoint is compared with the owning service's own output for the same inputs — the API
 * must never compute its own version of a figure.
 */
class CashAndReportsApiTest extends TestCase
{
    use RefreshDatabase;

    private FiscalPeriod $period;

    private CashBankAccount $cash;

    private CashBankAccount $bank;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 10:00:00');

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);

        $this->cash = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $this->bank = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();

        AccountingSetting::query()->updateOrCreate([], [
            'grni_account_id' => $this->account('2120')->id,
            'trade_payables_account_id' => $this->account('2110')->id,
            'trade_receivables_account_id' => $this->account('1210')->id,
        ]);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);

        $this->accountant = User::factory()->admin()->create(['name' => 'Mobile Accountant']);
        Sanctum::actingAs($this->accountant, ['mobile-api']);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function fund(CashBankAccount $target, string $amount): void
    {
        $entry = JournalEntry::create([
            'journal_number' => 'JV-OPEN-'.$target->id.'-'.substr(md5($amount), 0, 6),
            'journal_date' => '2026-09-01',
            'fiscal_period_id' => $this->period->id,
            'description' => 'Opening funds',
            'status' => JournalEntryStatus::Draft,
        ]);
        $entry->lines()->create(['account_id' => $target->account_id, 'debit' => $amount, 'credit' => '0.000000']);
        $entry->lines()->create(['account_id' => $this->account('3100')->id, 'debit' => '0.000000', 'credit' => $amount]);

        app(PostJournalEntry::class)->handle($entry->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function voucherPayload(array $extra = []): array
    {
        return array_merge([
            'voucher_date' => '2026-09-10',
            'cash_bank_account_id' => $this->bank->id,
            'payee_name' => 'Ceylon Electricity Board',
            'lines' => [
                ['expense_account_id' => $this->account('6400')->id, 'description' => 'September bill', 'amount' => '10000.50'],
            ],
        ], $extra);
    }

    /**
     * Some genuine activity for the reports to read: funding, a paid expense, a supplier
     * invoice owed and a sales invoice billed on a posted delivery.
     */
    private function tradeForAMonth(): void
    {
        $this->fund($this->bank, '500000.000000');

        $voucher = $this->postJson(route('api.v1.expense-vouchers.store'), $this->voucherPayload())->json('data.id');
        $this->postJson(route('api.v1.expense-vouchers.post', $voucher))->assertOk();

        $invoice = SupplierInvoice::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'invoice_date' => '2026-09-05',
            'due_date' => '2026-09-25',
        ]);
        $invoice->lines()->create([
            'line_type' => SupplierInvoiceLineType::DirectExpense,
            'debit_account_id' => $this->account('6100')->id,
            'description' => 'Transport',
            'amount' => '60000.000000',
        ]);
        app(PostSupplierInvoice::class)->handle($invoice->refresh());

        $nos = UnitOfMeasure::query()->firstOrCreate(['code' => 'NOS'], ['name' => 'Numbers', 'decimal_places' => 0]);
        $product = Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $nos->id]);
        ProductAccountMapping::create([
            'product_id' => $product->id,
            'inventory_account_id' => $this->account('1310')->id,
            'cogs_account_id' => $this->account('5110')->id,
            'revenue_account_id' => $this->account('4110')->id,
        ]);
        $warehouse = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $customer = Customer::factory()->create();
        $lot = app(CreateInventoryLot::class)->handle($product);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $product->id,
                warehouseId: $warehouse->id,
                direction: InventoryDirection::In,
                quantity: '1000.000',
                lotId: $lot->id,
                unitCost: '100.000000',
            ),
        ]);

        $delivery = Delivery::factory()->create(['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'delivery_date' => '2026-09-03']);
        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'quantity' => '500.000',
            'unit_price' => '130.000000',
        ]);
        $delivery = app(PostDelivery::class)->handle($delivery->refresh());

        $sale = SalesInvoice::factory()->create(['customer_id' => $customer->id, 'invoice_date' => '2026-09-05', 'due_date' => '2026-09-20']);
        $sale->lines()->create([
            'delivery_line_id' => $delivery->lines->first()->id,
            'product_id' => $product->id,
            'description' => 'Goods',
            'amount' => '65000.000000',
        ]);
        app(PostSalesInvoice::class)->handle($sale->refresh());
    }

    /**
     * A service's output as it travels over JSON, for comparison with an API response.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function asJson(array $payload): array
    {
        return json_decode((string) json_encode($payload), true);
    }

    // ------------------------------------------------------------- expense vouchers

    #[Test]
    public function an_expense_voucher_is_created_and_posted_and_the_cash_book_agrees_with_the_ledger(): void
    {
        $this->fund($this->bank, '100000.000000');

        $created = $this->postJson(route('api.v1.expense-vouchers.store'), $this->voucherPayload())->assertCreated();
        $created->assertJsonPath('data.status.value', 'DRAFT')
            ->assertJsonPath('data.lines.0.amount', '10000.500000')
            ->assertJsonPath('data.total', '10000.500000')
            ->assertJsonPath('data.created_by.name', 'Mobile Accountant');

        $posted = $this->postJson(route('api.v1.expense-vouchers.post', $created->json('data.id')))->assertOk();
        $posted->assertJsonPath('data.status.value', 'POSTED')
            ->assertJsonPath('data.posted_by.name', 'Mobile Accountant')
            ->assertJsonPath('data.journal.lines.0.debit', '10000.500000');

        $balance = app(AccountingBalanceService::class)->netBalance($this->bank->account_id);
        $this->assertSame('89999.500000', $balance);
        $this->assertTrue(app(CashBankBookService::class)->reconciles($this->bank));

        $this->getJson(route('api.v1.cash-bank-book.index', ['cash_bank_account_id' => $this->bank->id]))
            ->assertOk()
            ->assertJsonPath('data.ledger.balance', '89999.500000')
            ->assertJsonPath('data.ledger.reconciles', true);
    }

    #[Test]
    public function posting_a_voucher_twice_is_a_409_and_writes_one_journal(): void
    {
        $this->fund($this->bank, '100000.000000');
        $id = $this->postJson(route('api.v1.expense-vouchers.store'), $this->voucherPayload())->json('data.id');

        $this->postJson(route('api.v1.expense-vouchers.post', $id))->assertOk();
        $this->postJson(route('api.v1.expense-vouchers.post', $id))->assertStatus(409)->assertJsonStructure(['message', 'errors' => ['status']]);

        $this->assertSame(1, JournalEntry::where('source_type', ExpenseVoucher::class)->count());
        $this->assertSame('89999.500000', app(AccountingBalanceService::class)->netBalance($this->bank->account_id));
    }

    #[Test]
    public function a_posted_voucher_is_reversed_and_the_money_returns(): void
    {
        $this->fund($this->bank, '100000.000000');
        $id = $this->postJson(route('api.v1.expense-vouchers.store'), $this->voucherPayload())->json('data.id');
        $this->postJson(route('api.v1.expense-vouchers.post', $id));

        $this->postJson(route('api.v1.expense-vouchers.reverse', $id))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'REVERSED')
            ->assertJsonPath('data.reversed_by.name', 'Mobile Accountant');

        $this->assertSame('100000.000000', app(AccountingBalanceService::class)->netBalance($this->bank->account_id));
    }

    #[Test]
    public function a_draft_voucher_is_edited_and_cancelled_but_a_posted_one_cannot_be_edited(): void
    {
        $this->fund($this->bank, '100000.000000');
        $id = $this->postJson(route('api.v1.expense-vouchers.store'), $this->voucherPayload())->json('data.id');

        $this->putJson(route('api.v1.expense-vouchers.update', $id), $this->voucherPayload(['payee_name' => 'Changed']))
            ->assertOk()
            ->assertJsonPath('data.payee_name', 'Changed');

        $this->postJson(route('api.v1.expense-vouchers.cancel', $id))->assertOk()->assertJsonPath('data.status.value', 'CANCELLED');
        $this->assertSame(ExpenseVoucherStatus::Cancelled, ExpenseVoucher::find($id)->status);

        $posted = $this->postJson(route('api.v1.expense-vouchers.store'), $this->voucherPayload())->json('data.id');
        $this->postJson(route('api.v1.expense-vouchers.post', $posted));
        $this->putJson(route('api.v1.expense-vouchers.update', $posted), $this->voucherPayload())->assertStatus(409);
    }

    #[Test]
    public function the_voucher_options_offer_configured_accounts_and_never_cost_of_sales(): void
    {
        $options = $this->getJson(route('api.v1.expense-vouchers.options'))->assertOk()->json('data');

        $codes = collect($options['expense_accounts'])->pluck('code');
        $this->assertContains('6400', $codes);
        $this->assertNotContains('5110', $codes);
        $this->assertEqualsCanonicalizing([$this->cash->id, $this->bank->id], collect($options['cash_bank_accounts'])->pluck('id')->all());
    }

    // ------------------------------------------------------------- fund transfers

    #[Test]
    public function a_fund_transfer_leaves_combined_cash_and_bank_unchanged(): void
    {
        $this->fund($this->bank, '100000.000000');
        $book = app(CashBankBookService::class);
        $before = $book->combinedBalance();

        $id = $this->postJson(route('api.v1.fund-transfers.store'), [
            'transfer_date' => '2026-09-12',
            'from_cash_bank_account_id' => $this->bank->id,
            'to_cash_bank_account_id' => $this->cash->id,
            'amount' => '25000',
        ])->assertCreated()->assertJsonPath('data.amount', '25000.000000')->json('data.id');

        $this->postJson(route('api.v1.fund-transfers.post', $id))->assertOk()->assertJsonPath('data.status.value', 'POSTED');

        $this->assertSame($before, $book->combinedBalance());
        $this->assertSame('25000.000000', app(AccountingBalanceService::class)->netBalance($this->cash->account_id));

        $this->postJson(route('api.v1.fund-transfers.post', $id))->assertStatus(409);

        $this->postJson(route('api.v1.fund-transfers.reverse', $id))->assertOk()->assertJsonPath('data.status.value', 'REVERSED');
        $this->assertSame('0.000000', app(AccountingBalanceService::class)->netBalance($this->cash->account_id));
    }

    #[Test]
    public function a_transfer_to_the_same_account_is_refused_and_a_draft_can_be_cancelled(): void
    {
        $this->postJson(route('api.v1.fund-transfers.store'), [
            'transfer_date' => '2026-09-12',
            'from_cash_bank_account_id' => $this->bank->id,
            'to_cash_bank_account_id' => $this->bank->id,
            'amount' => '10',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_cash_bank_account_id');

        $transfer = FundTransfer::factory()->create();
        $this->postJson(route('api.v1.fund-transfers.cancel', $transfer))->assertOk();
        $this->assertSame(FundTransferStatus::Cancelled, $transfer->fresh()->status);
    }

    // ------------------------------------------------------------- reports agree with their services

    #[Test]
    public function the_profit_and_loss_matches_the_service_for_dates_and_for_a_fiscal_period(): void
    {
        $this->tradeForAMonth();
        $service = app(ProfitLossService::class);

        $this->getJson(route('api.v1.reports.profit-loss', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertExactJson(['data' => $this->asJson($service->statement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')))]);

        $body = $this->getJson(route('api.v1.reports.profit-loss', ['fiscal_period_id' => $this->period->id]))->json('data');
        $this->assertSame($this->asJson($service->statement(null, null, $this->period)), $body);
        $this->assertSame('65000.000000', $body['revenue']['total']);
        $this->assertIsString($body['net_profit']);
    }

    #[Test]
    public function the_balance_sheet_matches_the_service_and_includes_retained_earnings(): void
    {
        $this->tradeForAMonth();
        $service = app(BalanceSheetService::class);
        $asOf = Carbon::parse('2026-09-30');

        $body = $this->getJson(route('api.v1.reports.balance-sheet', ['as_of' => '2026-09-30']))->assertOk()->json('data');

        $expected = $this->asJson($service->statement($asOf) + ['retained_earnings' => $service->retainedEarnings($asOf)]);
        $this->assertSame($expected, $body);
        $this->assertTrue($body['balanced']);
        $this->assertSame('3200', $body['retained_earnings']['code']);
    }

    #[Test]
    public function inventory_vs_gl_matches_the_service(): void
    {
        $this->tradeForAMonth();

        $this->getJson(route('api.v1.reports.inventory-gl-reconciliation'))
            ->assertOk()
            ->assertExactJson(['data' => $this->asJson(app(InventoryGlReconciliationService::class)->report())]);
    }

    #[Test]
    public function ar_and_ap_aging_match_their_services_and_state_the_control_reconciliation(): void
    {
        $this->tradeForAMonth();

        $ar = $this->getJson(route('api.v1.ar-aging.index', ['as_of' => '2026-09-30']))->assertOk()->json('data');
        $expectedAr = $this->asJson(app(AccountsReceivableService::class)->aging(Carbon::parse('2026-09-30')));
        $this->assertSame($expectedAr, array_diff_key($ar, ['control' => true]));
        $this->assertSame('65000.000000', $ar['totals']['total']);
        $this->assertTrue($ar['control']['reconciles']);

        $ap = $this->getJson(route('api.v1.ap-aging.index', ['as_of' => '2026-09-30']))->assertOk()->json('data');
        $expectedAp = $this->asJson(app(AccountsPayableService::class)->aging(Carbon::parse('2026-09-30')));
        $this->assertSame($expectedAp, array_diff_key($ap, ['control' => true]));
        $this->assertSame('60000.000000', $ap['totals']['total']);
        $this->assertTrue($ap['control']['reconciles']);
    }

    #[Test]
    public function product_profitability_matches_the_service_to_the_last_decimal(): void
    {
        $this->tradeForAMonth();
        $product = Product::where('code', 'COCONUT')->firstOrFail();

        $body = $this->getJson(route('api.v1.reports.product-profitability', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk()->json('data');
        $this->assertSame($this->asJson(app(ProductProfitabilityService::class)->report(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))), $body);

        // 500 × 130 = 65,000 sales; 500 × 100 = 50,000 cost.
        $this->assertSame('65000.000000', $body['rows'][0]['sales_value']);
        $this->assertSame('50000.000000', $body['rows'][0]['cost_of_goods_sold']);
        $this->assertSame('15000.000000', $body['rows'][0]['gross_profit']);

        $drill = $this->getJson(route('api.v1.reports.product-profitability', ['product_id' => $product->id]))->json('data');
        $this->assertCount(1, $drill['deliveries']);
    }

    #[Test]
    public function the_finance_dashboard_matches_the_service_for_a_fiscal_period(): void
    {
        $this->tradeForAMonth();
        $service = app(FinanceDashboardService::class);

        $body = $this->getJson(route('api.v1.finance-dashboard.index', ['fiscal_period_id' => $this->period->id]))->assertOk()->json('data');

        $this->assertSame($this->asJson($service->summary(null, null, $this->period)), $body);
        $this->assertSame('September 2026', $body['period']['name']);
    }

    #[Test]
    public function the_remaining_reports_answer_with_their_services_output(): void
    {
        $this->tradeForAMonth();

        foreach ([
            'api.v1.reports.inventory-valuation',
            'api.v1.reports.cash-bank-position',
            'api.v1.grni-reconciliation.index',
            'api.v1.unbilled-deliveries.index',
        ] as $name) {
            $this->getJson(route($name))->assertOk()->assertJsonStructure(['data']);
        }

        $position = $this->getJson(route('api.v1.reports.cash-bank-position'))->json('data');
        $this->assertSame($this->asJson(app(CashBankBookService::class)->summary()), $position);
    }

    #[Test]
    public function malformed_report_filters_are_a_422_not_a_server_error(): void
    {
        $this->getJson(route('api.v1.reports.balance-sheet', ['as_of' => 'not-a-date']))->assertUnprocessable()->assertJsonValidationErrors('as_of');
        $this->getJson(route('api.v1.reports.profit-loss', ['fiscal_period_id' => 999999]))->assertUnprocessable();
        $this->getJson(route('api.v1.cash-bank-book.index'))->assertUnprocessable()->assertJsonValidationErrors('cash_bank_account_id');
    }

    // ------------------------------------------------------------- who may read finance

    #[Test]
    public function operational_roles_cannot_read_finance_values(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['PRODUCTION', 'STOREKEEPER'] as $role) {
            Sanctum::actingAs(User::factory()->withRoles($role)->create(), ['mobile-api']);

            foreach (['api.v1.reports.profit-loss', 'api.v1.reports.balance-sheet', 'api.v1.finance-dashboard.index', 'api.v1.ar-aging.index', 'api.v1.expense-vouchers.index'] as $name) {
                $response = $this->getJson(route($name))->assertForbidden();
                $this->assertArrayNotHasKey('data', $response->json(), "{$role} received finance data from {$name}.");
            }
        }
    }

    #[Test]
    public function a_cash_viewer_can_read_vouchers_but_not_post_them(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = ExpenseVoucher::factory()->create(['cash_bank_account_id' => $this->bank->id]);

        Sanctum::actingAs(User::factory()->withPermissions(Permission::CashView)->create(), ['mobile-api']);

        $this->getJson(route('api.v1.expense-vouchers.show', $voucher))->assertOk();
        $this->postJson(route('api.v1.expense-vouchers.post', $voucher))->assertForbidden();
        $this->assertSame(ExpenseVoucherStatus::Draft, $voucher->fresh()->status);
    }

    #[Test]
    public function money_in_the_voucher_list_is_exact_strings(): void
    {
        $this->fund($this->bank, '100000.000000');
        $this->postJson(route('api.v1.expense-vouchers.store'), $this->voucherPayload());

        $row = $this->getJson(route('api.v1.expense-vouchers.index'))->assertOk()->json('data.0');

        $this->assertIsString($row['total']);
        $this->assertSame(DecimalMath::cost('10000.5'), $row['total']);
        $this->assertSame('2026-09-10', $row['voucher_date']);
    }
}
