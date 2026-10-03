<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Accounts payable and receivable over the API — the same actions as the website, with the
 * Trade Payables and Trade Receivables invariants proven after each posting.
 */
class PayablesReceivablesApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Supplier $supplier;

    private Customer $customer;

    private Warehouse $main;

    private User $accountant;

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

        $this->supplier = Supplier::factory()->create(['code' => 'SUP-001']);
        $this->customer = Customer::factory()->create(['code' => 'CUS-001', 'credit_days' => 30]);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seedProductionRecipes();
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        FiscalPeriod::factory()->create(['name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);

        $this->accountant = User::factory()->withRoles('ACCOUNTANT')->create(['name' => 'Mobile Accountant']);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user, ['mobile-api']);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function balance(string $code): string
    {
        return app(AccountingBalanceService::class)->balance($this->account($code));
    }

    /** A posted receipt of 10,000 coconut at 100 plus 25,000 landed — 1,025,000 of GRNI. */
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
    private function supplierInvoicePayload(GoodsReceipt $receipt, string $amount = '1025000'): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_number' => 'INV-001',
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-25',
            'lines' => [['line_type' => 'GRNI_MATCH', 'goods_receipt_id' => $receipt->id, 'amount' => $amount]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesInvoicePayload(Delivery $delivery, string $amount = '260000'): array
    {
        return [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-10-10',
            'lines' => [['delivery_line_id' => $delivery->lines->first()->id, 'amount' => $amount]],
        ];
    }

    private function postedSupplierInvoice(): int
    {
        $id = $this->postJson(route('api.v1.supplier-invoices.store'), $this->supplierInvoicePayload($this->postReceipt()))->json('data.id');
        $this->postJson(route('api.v1.supplier-invoices.post', $id))->assertOk();

        return $id;
    }

    private function postedSalesInvoice(): int
    {
        $id = $this->postJson(route('api.v1.sales-invoices.store'), $this->salesInvoicePayload($this->postDelivery()))->json('data.id');
        $this->postJson(route('api.v1.sales-invoices.post', $id))->assertOk();

        return $id;
    }

    // ------------------------------------------------------------- accounts payable

    #[Test]
    public function a_supplier_invoice_is_created_and_posted_clearing_grni(): void
    {
        $this->actAs($this->accountant);
        $receipt = $this->postReceipt();

        $created = $this->postJson(route('api.v1.supplier-invoices.store'), $this->supplierInvoicePayload($receipt))
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'DRAFT')
            ->assertJsonPath('data.total', '1025000.000000')
            ->assertJsonPath('data.outstanding', '0.000000')
            ->assertJsonPath('data.lines.0.goods_receipt.grn_number', $receipt->grn_number)
            ->assertJsonPath('data.created_by.name', 'Mobile Accountant');

        $this->assertSame('0.000000', $this->balance('2110'), 'A draft owes nothing.');

        $this->postJson(route('api.v1.supplier-invoices.post', $created->json('data.id')))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'POSTED')
            ->assertJsonPath('data.outstanding', '1025000.000000')
            ->assertJsonPath('data.posted_by.name', 'Mobile Accountant');

        $this->assertSame('0.000000', $this->balance('2120'), 'GRNI is cleared.');
        $this->assertSame('1025000.000000', $this->balance('2110'));
        $this->assertSame($this->balance('2110'), app(AccountsPayableService::class)->totalOutstanding());
    }

    #[Test]
    public function reposting_a_supplier_invoice_is_a_409_and_changes_nothing(): void
    {
        $this->actAs($this->accountant);
        $id = $this->postedSupplierInvoice();

        $this->postJson(route('api.v1.supplier-invoices.post', $id))->assertStatus(409)->assertJsonStructure(['message', 'errors' => ['status']]);
        $this->assertSame('1025000.000000', $this->balance('2110'));
    }

    #[Test]
    public function grni_matching_stays_capped_at_the_remaining_accrual(): void
    {
        $this->actAs($this->accountant);
        $id = $this->postJson(route('api.v1.supplier-invoices.store'), $this->supplierInvoicePayload($this->postReceipt(), '2000000'))->json('data.id');

        $this->postJson(route('api.v1.supplier-invoices.post', $id))->assertUnprocessable();
        $this->assertSame(SupplierInvoiceStatus::Draft, SupplierInvoice::find($id)->status);
    }

    #[Test]
    public function a_draft_supplier_invoice_is_updated_and_a_posted_one_refuses_editing(): void
    {
        $this->actAs($this->accountant);
        $receipt = $this->postReceipt();
        $id = $this->postJson(route('api.v1.supplier-invoices.store'), $this->supplierInvoicePayload($receipt, '1000000'))->json('data.id');

        $this->putJson(route('api.v1.supplier-invoices.update', $id), $this->supplierInvoicePayload($receipt, '1025000'))
            ->assertOk()
            ->assertJsonPath('data.total', '1025000.000000');

        $this->postJson(route('api.v1.supplier-invoices.post', $id))->assertOk();
        $this->putJson(route('api.v1.supplier-invoices.update', $id), $this->supplierInvoicePayload($receipt))->assertStatus(409);
    }

    #[Test]
    public function a_supplier_payment_is_posted_and_the_trade_payables_invariant_holds(): void
    {
        $this->actAs($this->accountant);
        $invoiceId = $this->postedSupplierInvoice();

        $paymentId = $this->postJson(route('api.v1.supplier-payments.store'), [
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-09-20',
            'payment_account_id' => $this->account('1120')->id,
            'amount' => '500000',
            'payment_method' => 'BANK_TRANSFER',
            'allocations' => [['supplier_invoice_id' => $invoiceId, 'amount' => '500000']],
        ])->assertCreated()
            ->assertJsonPath('data.allocated', '500000.000000')
            ->assertJsonPath('data.payment_method.value', 'BANK_TRANSFER')
            ->assertJsonPath('data.cheque', null)
            ->json('data.id');

        $this->postJson(route('api.v1.supplier-payments.post', $paymentId))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'POSTED')
            ->assertJsonPath('data.posted_by.name', 'Mobile Accountant');

        $this->assertSame('525000.000000', $this->balance('2110'));
        $this->assertSame($this->balance('2110'), app(AccountsPayableService::class)->totalOutstanding());
        $this->getJson(route('api.v1.supplier-invoices.show', $invoiceId))
            ->assertJsonPath('data.status.value', 'PARTIALLY_PAID')
            ->assertJsonPath('data.outstanding', '525000.000000');

        $this->postJson(route('api.v1.supplier-payments.post', $paymentId))->assertStatus(409);
    }

    #[Test]
    public function an_invoice_with_a_posted_payment_cannot_be_reversed(): void
    {
        $this->actAs($this->accountant);
        $invoiceId = $this->postedSupplierInvoice();
        $paymentId = $this->postJson(route('api.v1.supplier-payments.store'), [
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-09-20',
            'payment_account_id' => $this->account('1120')->id,
            'amount' => '100000',
            'payment_method' => 'BANK_TRANSFER',
            'allocations' => [['supplier_invoice_id' => $invoiceId, 'amount' => '100000']],
        ])->json('data.id');
        $this->postJson(route('api.v1.supplier-payments.post', $paymentId))->assertOk();

        $this->postJson(route('api.v1.supplier-invoices.reverse', $invoiceId))->assertStatus(409);

        $this->postJson(route('api.v1.supplier-payments.reverse', $paymentId))->assertOk()->assertJsonPath('data.status.value', 'REVERSED');
        $this->postJson(route('api.v1.supplier-invoices.reverse', $invoiceId))->assertOk()->assertJsonPath('data.reversed_by.name', 'Mobile Accountant');
        $this->assertSame('0.000000', $this->balance('2110'));
        $this->assertSame($this->balance('2110'), app(AccountsPayableService::class)->totalOutstanding());
    }

    #[Test]
    public function a_payment_must_come_from_a_configured_cash_or_bank_account(): void
    {
        $this->actAs($this->accountant);
        $invoiceId = $this->postedSupplierInvoice();

        // As on the website, the draft may name any account; posting applies
        // AccountingPolicy::assertCashBankAccount() and refuses an unconfigured one.
        $paymentId = $this->postJson(route('api.v1.supplier-payments.store'), [
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-09-20',
            'payment_account_id' => $this->account('1400')->id,
            'amount' => '100000',
            'payment_method' => 'BANK_TRANSFER',
            'allocations' => [['supplier_invoice_id' => $invoiceId, 'amount' => '100000']],
        ])->assertCreated()->json('data.id');

        $this->postJson(route('api.v1.supplier-payments.post', $paymentId))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');
        $this->assertSame('1025000.000000', $this->balance('2110'));
    }

    // ------------------------------------------------------------- accounts receivable

    #[Test]
    public function a_sales_invoice_bills_the_delivery_at_its_exact_sales_value(): void
    {
        $this->actAs($this->accountant);
        $delivery = $this->postDelivery();

        $id = $this->postJson(route('api.v1.sales-invoices.store'), $this->salesInvoicePayload($delivery))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.delivered_value', '260000.000000')
            ->assertJsonPath('data.lines.0.amount', '260000.000000')
            ->assertJsonPath('data.lines.0.delivery.delivery_number', $delivery->delivery_number)
            ->json('data.id');

        $this->postJson(route('api.v1.sales-invoices.post', $id))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'POSTED')
            ->assertJsonPath('data.outstanding', '260000.000000')
            ->assertJsonPath('data.posted_by.name', 'Mobile Accountant');

        $this->assertSame('260000.000000', $this->balance('1210'));
        $this->assertSame($this->balance('1210'), app(AccountsReceivableService::class)->totalOutstanding());

        $this->postJson(route('api.v1.sales-invoices.post', $id))->assertStatus(409);
    }

    #[Test]
    public function billing_more_than_the_delivered_value_is_refused(): void
    {
        $this->actAs($this->accountant);
        $id = $this->postJson(route('api.v1.sales-invoices.store'), $this->salesInvoicePayload($this->postDelivery(), '300000'))->json('data.id');

        if ($id !== null) {
            $this->postJson(route('api.v1.sales-invoices.post', $id))->assertUnprocessable();
            $this->assertSame(SalesInvoiceStatus::Draft, SalesInvoice::find($id)->status);
        }

        $this->assertSame('0.000000', $this->balance('1210'));
    }

    #[Test]
    public function a_customer_receipt_is_posted_and_the_trade_receivables_invariant_holds(): void
    {
        $this->actAs($this->accountant);
        $invoiceId = $this->postedSalesInvoice();

        $receiptId = $this->postJson(route('api.v1.customer-receipts.store'), [
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-09-20',
            'receipt_account_id' => $this->account('1120')->id,
            'amount' => '100000',
            'payment_method' => 'CHEQUE',
            'cheque_number' => '558812',
            'cheque_date' => '2026-09-19',
            'cheque_bank' => 'Bank of Ceylon',
            'allocations' => [['sales_invoice_id' => $invoiceId, 'amount' => '100000']],
        ])->assertCreated()
            ->assertJsonPath('data.amount', '100000.000000')
            ->assertJsonPath('data.payment_method.value', 'CHEQUE')
            ->assertJsonPath('data.cheque.number', '558812')
            ->assertJsonPath('data.cheque.date', '2026-09-19')
            ->assertJsonPath('data.cheque.bank', 'Bank of Ceylon')
            ->json('data.id');

        $this->postJson(route('api.v1.customer-receipts.post', $receiptId))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'POSTED')
            ->assertJsonPath('data.posted_by.name', 'Mobile Accountant');

        $this->assertSame('160000.000000', $this->balance('1210'));
        $this->assertSame($this->balance('1210'), app(AccountsReceivableService::class)->totalOutstanding());

        // A posted receipt blocks reversing its invoice; reversing the receipt restores the debt.
        $this->postJson(route('api.v1.sales-invoices.reverse', $invoiceId))->assertStatus(409);
        $this->postJson(route('api.v1.customer-receipts.reverse', $receiptId))->assertOk()->assertJsonPath('data.reversed_by.name', 'Mobile Accountant');
        $this->assertSame('260000.000000', $this->balance('1210'));
        $this->assertSame($this->balance('1210'), app(AccountsReceivableService::class)->totalOutstanding());
    }

    #[Test]
    public function draft_documents_can_be_cancelled_and_posted_ones_cannot(): void
    {
        $this->actAs($this->accountant);
        $draft = $this->postJson(route('api.v1.sales-invoices.store'), $this->salesInvoicePayload($this->postDelivery(), '1000'))->json('data.id');
        $this->postJson(route('api.v1.sales-invoices.cancel', $draft))->assertOk()->assertJsonPath('data.status.value', 'CANCELLED');

        $posted = $this->postJson(route('api.v1.sales-invoices.store'), $this->salesInvoicePayload(Delivery::first(), '1000'))->json('data.id');
        $this->postJson(route('api.v1.sales-invoices.post', $posted))->assertOk();
        $this->postJson(route('api.v1.sales-invoices.cancel', $posted))->assertStatus(409);
    }

    // ------------------------------------------------------------- permissions

    #[Test]
    public function sales_and_storekeeper_roles_cannot_touch_invoices(): void
    {
        $this->actAs($this->accountant);
        $delivery = $this->postDelivery();
        $invoiceId = $this->postJson(route('api.v1.sales-invoices.store'), $this->salesInvoicePayload($delivery))->json('data.id');

        foreach (['SALES', 'STOREKEEPER'] as $role) {
            $this->actAs(User::factory()->withRoles($role)->create());

            $this->postJson(route('api.v1.sales-invoices.post', $invoiceId))->assertForbidden()->assertJsonStructure(['message']);
            $this->getJson(route('api.v1.supplier-invoices.index'))->assertForbidden();
        }

        $this->assertSame(SalesInvoiceStatus::Draft, SalesInvoice::find($invoiceId)->status);
        $this->assertSame('0.000000', $this->balance('1210'));
    }

    #[Test]
    public function the_manager_can_read_payables_but_not_post_them(): void
    {
        $this->actAs($this->accountant);
        $id = $this->postJson(route('api.v1.supplier-invoices.store'), $this->supplierInvoicePayload($this->postReceipt()))->json('data.id');

        $this->actAs(User::factory()->withRoles('MANAGER')->create());

        $this->getJson(route('api.v1.supplier-invoices.index'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(route('api.v1.supplier-invoices.show', $id))->assertOk();
        $this->postJson(route('api.v1.supplier-invoices.post', $id))->assertForbidden();
    }

    // ------------------------------------------------------------- wire format

    #[Test]
    public function money_travels_as_strings_and_lists_paginate(): void
    {
        $this->actAs($this->accountant);
        $this->postedSupplierInvoice();

        $response = $this->getJson(route('api.v1.supplier-invoices.index', ['per_page' => 500]))
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 1);

        $row = $response->json('data.0');
        $this->assertIsString($row['total']);
        $this->assertIsString($row['outstanding']);
        $this->assertSame('2026-09-10', $row['invoice_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $row['posted_at']);
    }
}
