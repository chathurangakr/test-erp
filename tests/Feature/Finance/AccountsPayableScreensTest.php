<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Enums\PaymentMethod;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\Account;
use App\Models\CompanyProfile;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierPayment;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The accounts payable screens.
 *
 * The assertions that matter most are the ones about what a user cannot do: reach a control
 * account through a direct-expense line, or type the credit side of an invoice.
 */
class AccountsPayableScreensTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $main;

    private Product $coconut;

    private FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $nos->id]);
        foreach ([['DEHUSKED-COCONUT', $nos], ['RAW-HUSK', $nos], ['COPRA', $kg], ['HUSK-7C', $kg], ['HUSK-9C', $kg], ['HUSK-10C', $kg]] as [$code, $uom]) {
            Product::factory()->create(['code' => $code, 'primary_uom_id' => $uom->id]);
        }
        Product::factory()->create(['code' => 'RAW-SHELL', 'primary_uom_id' => $nos->id, 'secondary_uom_id' => $kg->id]);

        $this->supplier = Supplier::factory()->create(['code' => 'TEST-SUPPLIER']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seedProductionRecipes();
        $this->seed(ChartOfAccountsSeeder::class);
        // Money may only move through a configured cash or bank account.
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);

        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function postReceipt(): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-05',
            'transport_cost' => '20000.000000',
            'loading_cost' => '5000.000000',
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->coconut->id,
            'quantity' => '10000.000',
            'unit_price' => '100.000000',
        ]);

        return app(PostGoodsReceipt::class)->handle($receipt->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(GoodsReceipt $receipt, array $extra = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_number' => 'INV-001',
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-25',
            'lines' => [
                [
                    'line_type' => 'GRNI_MATCH',
                    'goods_receipt_id' => $receipt->id,
                    'debit_account_id' => null,
                    'amount' => '1000000.000000',
                ],
            ],
        ], $extra);
    }

    #[Test]
    public function a_guest_cannot_reach_any_payables_screen(): void
    {
        $invoice = SupplierInvoice::factory()->create(['supplier_id' => $this->supplier->id]);
        $payment = SupplierPayment::factory()->create([
            'supplier_id' => $this->supplier->id,
            'payment_account_id' => $this->account('1120')->id,
        ]);

        $this->get(route('supplier-invoices.index'))->assertRedirect(route('login'));
        $this->get(route('supplier-invoices.create'))->assertRedirect(route('login'));
        $this->get(route('supplier-invoices.show', $invoice))->assertRedirect(route('login'));
        $this->post(route('supplier-invoices.store'), [])->assertRedirect(route('login'));
        $this->patch(route('supplier-invoices.post', $invoice))->assertRedirect(route('login'));

        $this->get(route('supplier-payments.index'))->assertRedirect(route('login'));
        $this->get(route('supplier-payments.create'))->assertRedirect(route('login'));
        $this->get(route('supplier-payments.show', $payment))->assertRedirect(route('login'));
        $this->patch(route('supplier-payments.reverse', $payment))->assertRedirect(route('login'));

        $this->get(route('ap-aging.index'))->assertRedirect(route('login'));
        $this->get(route('grni-reconciliation.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_reach_every_payables_screen(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('supplier-invoices.index'))->assertOk();
        $this->actingAs($user)->get(route('supplier-invoices.create'))->assertOk();
        $this->actingAs($user)->get(route('supplier-payments.index'))->assertOk();
        $this->actingAs($user)->get(route('supplier-payments.create'))->assertOk();
        $this->actingAs($user)->get(route('ap-aging.index'))->assertOk();
        $this->actingAs($user)->get(route('grni-reconciliation.index'))->assertOk();
    }

    #[Test]
    public function there_are_no_destroy_routes_in_payables(): void
    {
        foreach (['supplier-invoices.destroy', 'supplier-payments.destroy'] as $name) {
            $this->assertFalse(app('router')->has($name), "{$name} must not exist — AP history is permanent.");
        }
    }

    #[Test]
    public function the_create_screen_offers_only_receipts_with_accrual_left(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)
            ->get(route('supplier-invoices.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/supplier-invoices/Create')
                ->has('goodsReceipts', 1)
                ->where('goodsReceipts.0.grn_number', $receipt->grn_number)
                ->where('goodsReceipts.0.original_accrual', '1025000.000000')
                ->where('goodsReceipts.0.remaining_amount', '1025000.000000')
                ->where('currency', 'LKR'));
    }

    #[Test]
    public function a_fully_invoiced_receipt_drops_off_the_create_screen(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $invoice = SupplierInvoice::factory()->create([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-25',
        ]);
        SupplierInvoiceLine::factory()->grniMatch($receipt->id, '1025000.000000')->create([
            'supplier_invoice_id' => $invoice->id,
        ]);
        app(PostSupplierInvoice::class)->handle($invoice);

        $this->actingAs($user)
            ->get(route('supplier-invoices.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('goodsReceipts', 0));
    }

    #[Test]
    public function the_create_screen_offers_only_ordinary_expense_accounts(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('supplier-invoices.create'));
        $codes = collect($response->viewData('page')['props']['expenseAccounts'])->pluck('code');

        $this->assertTrue($codes->contains('6400'));
        // No control accounts, no group headers, no inventory.
        $this->assertFalse($codes->contains('6000'));
        $this->assertFalse($codes->contains('1310'));
        $this->assertFalse($codes->contains('2110'));
        $this->assertFalse($codes->contains('2120'));
    }

    #[Test]
    public function an_invoice_can_be_created_and_posted_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)
            ->post(route('supplier-invoices.store'), $this->invoicePayload($receipt))
            ->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::firstOrFail();

        $this->assertMatchesRegularExpression('/^PINV-\d{8}-\d{6}$/', $invoice->internal_invoice_number);
        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->status);

        $this->actingAs($user)
            ->patch(route('supplier-invoices.post', $invoice))
            ->assertSessionHasNoErrors();

        $this->assertSame(SupplierInvoiceStatus::Posted, $invoice->fresh()->status);
    }

    #[Test]
    public function the_screen_refuses_a_duplicate_invoice_number_for_one_supplier(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)->post(route('supplier-invoices.store'), $this->invoicePayload($receipt));

        $this->actingAs($user)
            ->post(route('supplier-invoices.store'), $this->invoicePayload($receipt, [
                'supplier_invoice_number' => ' inv-001 ',
            ]))
            ->assertSessionHasErrors('supplier_invoice_number');

        $this->assertSame(1, SupplierInvoice::count());
    }

    #[Test]
    public function the_screen_refuses_a_grni_line_with_no_receipt_and_an_expense_line_with_no_account(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)->post(route('supplier-invoices.store'), $this->invoicePayload($receipt, [
            'lines' => [['line_type' => 'GRNI_MATCH', 'goods_receipt_id' => null, 'amount' => '100.000000']],
        ]))->assertSessionHasErrors('lines.0.goods_receipt_id');

        $this->actingAs($user)->post(route('supplier-invoices.store'), $this->invoicePayload($receipt, [
            'lines' => [['line_type' => 'DIRECT_EXPENSE', 'debit_account_id' => null, 'amount' => '100.000000']],
        ]))->assertSessionHasErrors('lines.0.debit_account_id');

        $this->assertSame(0, SupplierInvoice::count());
    }

    #[Test]
    public function the_screen_refuses_a_due_date_before_the_invoice_date(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)
            ->post(route('supplier-invoices.store'), $this->invoicePayload($receipt, ['due_date' => '2026-09-01']))
            ->assertSessionHasErrors('due_date');
    }

    #[Test]
    public function the_invoice_show_screen_reports_its_journal_and_balances(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)->post(route('supplier-invoices.store'), $this->invoicePayload($receipt));
        $invoice = SupplierInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('supplier-invoices.post', $invoice));

        $this->actingAs($user)
            ->get(route('supplier-invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/supplier-invoices/Show')
                ->where('invoice.status', 'POSTED')
                ->where('invoice.total', '1000000.000000')
                ->where('invoice.paid', '0.000000')
                ->where('invoice.outstanding', '1000000.000000')
                ->where('invoice.can_reverse', true)
                ->where('invoice.can_edit', false)
                ->has('invoice.lines', 1)
                ->has('invoice.journal.lines', 2));
    }

    #[Test]
    public function the_printed_invoice_shows_what_was_recorded_and_the_letterhead(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();
        CompanyProfile::query()->create(['name' => 'Factory Co', 'address' => '1 Mill Road']);

        $this->actingAs($user)->post(route('supplier-invoices.store'), $this->invoicePayload($receipt));
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($user)
            ->get(route('supplier-invoices.print', $invoice))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/supplier-invoices/Print')
                ->where('company.name', 'Factory Co')
                ->where('company.address', '1 Mill Road')
                ->where('invoice.status', 'DRAFT')
                ->where('invoice.supplier.name', $this->supplier->name)
                ->where('invoice.total', '1000000.000000')
                ->has('invoice.lines', 1)
                ->where('invoice.lines.0.line_type', 'GRNI_MATCH')
                ->where('invoice.lines.0.grn_number', $receipt->grn_number)
                ->where('invoice.lines.0.amount', '1000000.000000'));
    }

    #[Test]
    public function the_payment_screen_lists_what_a_supplier_owes(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)->post(route('supplier-invoices.store'), $this->invoicePayload($receipt));
        $invoice = SupplierInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('supplier-invoices.post', $invoice));

        $this->actingAs($user)
            ->get(route('supplier-payments.create', ['supplier_id' => $this->supplier->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/supplier-payments/Create')
                ->has('outstandingInvoices', 1)
                ->where('outstandingInvoices.0.outstanding', '1000000.000000')
                // Only CONFIGURED cash/bank accounts may pay — not every asset account.
                ->has('paymentAccounts', 2)
                ->where('paymentAccounts.0.code', '1110')
                ->where('paymentAccounts.1.code', '1120'));
    }

    #[Test]
    public function a_payment_can_be_created_and_posted_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)->post(route('supplier-invoices.store'), $this->invoicePayload($receipt));
        $invoice = SupplierInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('supplier-invoices.post', $invoice));

        $this->actingAs($user)->post(route('supplier-payments.store'), [
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-09-20',
            'payment_account_id' => $this->account('1120')->id,
            'reference' => 'CHQ-1001',
            'amount' => '400000.000000',
            'payment_method' => 'CHEQUE',
            'cheque_number' => '1001',
            'cheque_date' => '2026-09-20',
            'cheque_bank' => 'People\'s Bank',
            'allocations' => [
                ['supplier_invoice_id' => $invoice->id, 'amount' => '400000.000000'],
            ],
        ])->assertSessionHasNoErrors();

        $payment = SupplierPayment::firstOrFail();
        $this->assertMatchesRegularExpression('/^PAY-\d{8}-\d{6}$/', $payment->payment_number);
        $this->assertSame(PaymentMethod::Cheque, $payment->payment_method);
        $this->assertSame('1001', $payment->cheque_number);

        $this->actingAs($user)
            ->patch(route('supplier-payments.post', $payment))
            ->assertSessionHasNoErrors();

        $this->assertSame(SupplierPaymentStatus::Posted, $payment->fresh()->status);
        $this->assertSame(SupplierInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
    }

    #[Test]
    public function the_aging_and_reconciliation_screens_report_and_reconcile(): void
    {
        $user = User::factory()->admin()->create();
        $receipt = $this->postReceipt();

        $this->actingAs($user)->post(route('supplier-invoices.store'), $this->invoicePayload($receipt));
        $invoice = SupplierInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('supplier-invoices.post', $invoice));

        $this->actingAs($user)
            ->get(route('ap-aging.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/ap-aging/Index')
                ->has('report.rows', 1)
                ->where('report.totals.total', '1000000.000000')
                ->where('control.code', '2110')
                ->where('control.reconciles', true));

        $this->actingAs($user)
            ->get(route('grni-reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/grni-reconciliation/Index')
                ->has('report.rows', 1)
                ->where('report.original_total', '1025000.000000')
                ->where('report.matched_total', '1000000.000000')
                ->where('report.remaining_total', '25000.000000')
                ->where('report.rows.0.status', 'PARTIALLY_MATCHED')
                ->where('control.code', '2120')
                ->where('control.reconciles', true));
    }
}
