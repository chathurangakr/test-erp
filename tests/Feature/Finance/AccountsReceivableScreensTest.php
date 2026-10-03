<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\CustomerReceiptStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\SalesInvoiceStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The accounts receivable screens.
 *
 * The assertions that matter most are the negative ones: no revenue account is ever offered,
 * and a delivery that has not reached the ledger is not billable.
 */
class AccountsReceivableScreensTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

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

        $this->customer = Customer::factory()->create(['code' => 'CUS-001', 'credit_days' => 30]);
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

    /** A posted delivery of 2,000 coconut at 130 — 260,000 billable. */
    private function postDelivery(): Delivery
    {
        $lot = app(CreateInventoryLot::class)->handle($this->coconut);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->coconut->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: '10000.000',
                lotId: $lot->id,
                unitCost: '102.500000',
            ),
        ]);

        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-05',
        ]);

        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->coconut->id,
            'lot_id' => $lot->id,
            'quantity' => '2000.000',
            'unit_price' => '130.000000',
        ]);

        return app(PostDelivery::class)->handle($delivery->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Delivery $delivery, array $extra = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-25',
            'lines' => [
                ['delivery_line_id' => $delivery->lines->first()->id, 'amount' => '260000.000000'],
            ],
        ], $extra);
    }

    #[Test]
    public function a_guest_cannot_reach_any_receivables_screen(): void
    {
        $invoice = SalesInvoice::factory()->create(['customer_id' => $this->customer->id]);
        $receipt = CustomerReceipt::factory()->create([
            'customer_id' => $this->customer->id,
            'receipt_account_id' => $this->account('1120')->id,
        ]);

        $this->get(route('sales-invoices.index'))->assertRedirect(route('login'));
        $this->get(route('sales-invoices.create'))->assertRedirect(route('login'));
        $this->get(route('sales-invoices.show', $invoice))->assertRedirect(route('login'));
        $this->post(route('sales-invoices.store'), [])->assertRedirect(route('login'));
        $this->patch(route('sales-invoices.post', $invoice))->assertRedirect(route('login'));

        $this->get(route('customer-receipts.index'))->assertRedirect(route('login'));
        $this->get(route('customer-receipts.create'))->assertRedirect(route('login'));
        $this->get(route('customer-receipts.show', $receipt))->assertRedirect(route('login'));
        $this->patch(route('customer-receipts.reverse', $receipt))->assertRedirect(route('login'));

        $this->get(route('ar-aging.index'))->assertRedirect(route('login'));
        $this->get(route('unbilled-deliveries.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_reach_every_receivables_screen(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('sales-invoices.index'))->assertOk();
        $this->actingAs($user)->get(route('sales-invoices.create'))->assertOk();
        $this->actingAs($user)->get(route('customer-receipts.index'))->assertOk();
        $this->actingAs($user)->get(route('customer-receipts.create'))->assertOk();
        $this->actingAs($user)->get(route('ar-aging.index'))->assertOk();
        $this->actingAs($user)->get(route('unbilled-deliveries.index'))->assertOk();
    }

    #[Test]
    public function there_are_no_destroy_routes_in_receivables(): void
    {
        foreach (['sales-invoices.destroy', 'customer-receipts.destroy'] as $name) {
            $this->assertFalse(app('router')->has($name), "{$name} must not exist — AR history is permanent.");
        }
    }

    #[Test]
    public function the_create_screen_offers_the_customers_unbilled_delivered_lines(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $this->actingAs($user)
            ->get(route('sales-invoices.create', ['customer_id' => $this->customer->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/sales-invoices/Create')
                ->has('billableLines', 1)
                ->where('billableLines.0.delivery_number', $delivery->delivery_number)
                ->where('billableLines.0.original_value', '260000.000000')
                ->where('billableLines.0.remaining_amount', '260000.000000')
                // The customer's agreed terms suggest the due date.
                ->where('creditDays', 30)
                ->where('currency', 'LKR'));
    }

    /**
     * Nothing anywhere in AR lets a user choose where the credit lands.
     */
    #[Test]
    public function the_create_screen_never_offers_a_revenue_account(): void
    {
        $user = User::factory()->admin()->create();
        $this->postDelivery();

        $response = $this->actingAs($user)->get(route('sales-invoices.create', ['customer_id' => $this->customer->id]));
        $props = $response->viewData('page')['props'];

        foreach (['revenueAccounts', 'accounts', 'accountOptions'] as $key) {
            $this->assertArrayNotHasKey($key, $props);
        }
    }

    #[Test]
    public function a_fully_billed_delivery_drops_off_the_create_screen(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $invoice = SalesInvoice::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-25',
        ]);
        SalesInvoiceLine::factory()->forDeliveryLine($delivery->lines->first(), '260000.000000')->create([
            'sales_invoice_id' => $invoice->id,
        ]);
        app(PostSalesInvoice::class)->handle($invoice);

        $this->actingAs($user)
            ->get(route('sales-invoices.create', ['customer_id' => $this->customer->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('billableLines', 0));
    }

    #[Test]
    public function an_invoice_can_be_created_and_posted_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $this->actingAs($user)
            ->post(route('sales-invoices.store'), $this->invoicePayload($delivery))
            ->assertSessionHasNoErrors();

        $invoice = SalesInvoice::firstOrFail();

        $this->assertMatchesRegularExpression('/^SINV-\d{8}-\d{6}$/', $invoice->invoice_number);
        $this->assertSame(SalesInvoiceStatus::Draft, $invoice->status);
        // The product was copied from the delivered line, not chosen.
        $this->assertSame($this->coconut->id, $invoice->lines->first()->product_id);

        $this->actingAs($user)
            ->patch(route('sales-invoices.post', $invoice))
            ->assertSessionHasNoErrors();

        $this->assertSame(SalesInvoiceStatus::Posted, $invoice->fresh()->status);
    }

    #[Test]
    public function the_screen_refuses_a_due_date_before_the_invoice_date(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $this->actingAs($user)
            ->post(route('sales-invoices.store'), $this->invoicePayload($delivery, ['due_date' => '2026-09-01']))
            ->assertSessionHasErrors('due_date');
    }

    #[Test]
    public function the_screen_refuses_an_invoice_with_no_lines(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $this->actingAs($user)
            ->post(route('sales-invoices.store'), $this->invoicePayload($delivery, ['lines' => []]))
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, SalesInvoice::count());
    }

    #[Test]
    public function the_invoice_show_screen_reports_its_journal_and_balances(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $this->actingAs($user)->post(route('sales-invoices.store'), $this->invoicePayload($delivery));
        $invoice = SalesInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('sales-invoices.post', $invoice));

        $this->actingAs($user)
            ->get(route('sales-invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/sales-invoices/Show')
                ->where('invoice.status', 'POSTED')
                ->where('invoice.total', '260000.000000')
                ->where('invoice.received', '0.000000')
                ->where('invoice.outstanding', '260000.000000')
                ->where('invoice.can_reverse', true)
                ->where('invoice.can_edit', false)
                ->has('invoice.lines', 1)
                ->where('invoice.lines.0.delivered_value', '260000.000000')
                ->has('invoice.journal.lines', 2));
    }

    #[Test]
    public function the_receipt_screen_lists_what_a_customer_owes(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $this->actingAs($user)->post(route('sales-invoices.store'), $this->invoicePayload($delivery));
        $invoice = SalesInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('sales-invoices.post', $invoice));

        $this->actingAs($user)
            ->get(route('customer-receipts.create', ['customer_id' => $this->customer->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/customer-receipts/Create')
                ->has('outstandingInvoices', 1)
                ->where('outstandingInvoices.0.outstanding', '260000.000000')
                // Only CONFIGURED cash/bank accounts may receive — not every asset account.
                ->has('receiptAccounts', 2)
                ->where('receiptAccounts.0.code', '1110')
                ->where('receiptAccounts.1.code', '1120'));
    }

    #[Test]
    public function a_receipt_can_be_created_and_posted_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $this->actingAs($user)->post(route('sales-invoices.store'), $this->invoicePayload($delivery));
        $invoice = SalesInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('sales-invoices.post', $invoice));

        $this->actingAs($user)->post(route('customer-receipts.store'), [
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-09-20',
            'receipt_account_id' => $this->account('1120')->id,
            'reference' => 'CHQ-2001',
            'amount' => '100000.000000',
            'payment_method' => 'CHEQUE',
            'cheque_number' => '2001',
            'cheque_date' => '2026-09-20',
            'cheque_bank' => 'Commercial Bank',
            'allocations' => [
                ['sales_invoice_id' => $invoice->id, 'amount' => '100000.000000'],
            ],
        ])->assertSessionHasNoErrors();

        $receipt = CustomerReceipt::firstOrFail();
        $this->assertMatchesRegularExpression('/^REC-\d{8}-\d{6}$/', $receipt->receipt_number);
        $this->assertSame(PaymentMethod::Cheque, $receipt->payment_method);
        $this->assertSame('2001', $receipt->cheque_number);

        $this->actingAs($user)
            ->patch(route('customer-receipts.post', $receipt))
            ->assertSessionHasNoErrors();

        $this->assertSame(CustomerReceiptStatus::Posted, $receipt->fresh()->status);
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
    }

    #[Test]
    public function the_customer_screen_reports_what_that_customer_owes(): void
    {
        // Before the fixture's 2026-09-25 due date, so "nothing overdue" does not depend on
        // the real clock.
        $this->travelTo('2026-09-20');

        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        $this->actingAs($user)->post(route('sales-invoices.store'), $this->invoicePayload($delivery));
        $invoice = SalesInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('sales-invoices.post', $invoice));

        $this->actingAs($user)
            ->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('receivables.outstanding', '260000.000000')
                ->where('receivables.overdue', '0.000000')
                ->has('receivables.invoices', 1)
                ->where('receivables.invoices.0.outstanding', '260000.000000')
                ->has('receivables.receipts', 0));

        // The customer master itself still holds no balance of any kind.
        $this->assertFalse(
            Schema::hasColumn('customers', 'balance'),
            'A customer must never carry a stored receivable balance.',
        );
    }

    #[Test]
    public function the_aging_and_unbilled_screens_report_and_reconcile(): void
    {
        $user = User::factory()->admin()->create();
        $delivery = $this->postDelivery();

        // Bill only part of it, so both reports have something to show.
        $this->actingAs($user)->post(route('sales-invoices.store'), $this->invoicePayload($delivery, [
            'lines' => [['delivery_line_id' => $delivery->lines->first()->id, 'amount' => '200000.000000']],
        ]));
        $invoice = SalesInvoice::firstOrFail();
        $this->actingAs($user)->patch(route('sales-invoices.post', $invoice));

        $this->actingAs($user)
            ->get(route('ar-aging.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/ar-aging/Index')
                ->has('report.rows', 1)
                ->where('report.totals.total', '200000.000000')
                ->where('control.code', '1210')
                ->where('control.reconciles', true));

        $this->actingAs($user)
            ->get(route('unbilled-deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/unbilled-deliveries/Index')
                ->has('report.rows', 1)
                ->where('report.value_total', '260000.000000')
                ->where('report.billed_total', '200000.000000')
                ->where('report.remaining_total', '60000.000000')
                ->where('report.rows.0.status', 'PARTIALLY_BILLED'));
    }
}
