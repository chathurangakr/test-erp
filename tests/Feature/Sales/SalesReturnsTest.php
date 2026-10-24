<?php

namespace Tests\Feature\Sales;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Receivables\ReverseSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\RecordSalesReturn;
use App\Actions\Sales\ReverseSalesReturn;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\Permission;
use App\Enums\PricingBasis;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesReturnStatus;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Goods customers brought back (owner's rules, 2026-10-24): credited at the price charged,
 * good goods back in stock at the cost they left with, damaged ones a loss, settled by reducing
 * an invoice, an exchange or money back. The receivables invariant must keep holding:
 *
 *   Trade Receivables general-ledger balance  ==  total invoice outstanding
 */
class SalesReturnsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Product> */
    private array $products = [];

    private Customer $customer;

    private Warehouse $main;

    private InventoryLot $coconutLot;

    private DeliveryLine $line;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        foreach ([['COCONUT', $nos], ['DEHUSKED-COCONUT', $nos], ['RAW-HUSK', $nos], ['COPRA', $kg]] as [$code, $uom]) {
            $this->products[$code] = Product::factory()->create(['code' => $code, 'primary_uom_id' => $uom->id]);
        }

        $this->customer = Customer::factory()->create(['code' => 'CUS-001']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seedProductionRecipes();
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);

        // 2,000 coconuts delivered at Rs. 130 that cost Rs. 102.50, invoiced for Rs. 260,000.
        $this->coconutLot = $this->stockUp('COCONUT', '10000.000', '102.500000');
        $this->line = $this->deliver('COCONUT', '2000.000', '130.000000', $this->coconutLot)->lines->first();
        $this->invoice = $this->invoice([[$this->line, '260000.000000']]);
    }

    private function stockUp(string $code, string $quantity, string $unitCost): InventoryLot
    {
        $lot = app(CreateInventoryLot::class)->handle($this->products[$code]);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine($this->products[$code]->id, $this->main->id, InventoryDirection::In, $quantity, $lot->id, unitCost: $unitCost),
        ]);

        return $lot;
    }

    private function deliver(string $code, string $quantity, string $price, InventoryLot $lot): Delivery
    {
        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-05',
        ]);

        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->products[$code]->id,
            'lot_id' => $lot->id,
            'quantity' => $quantity,
            'pricing_basis' => PricingBasis::Primary,
            'unit_price' => $price,
        ]);

        return app(PostDelivery::class)->handle($delivery->refresh());
    }

    /**
     * @param  list<array{0: DeliveryLine, 1: string}>  $lines
     */
    private function invoice(array $lines): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-25',
        ]);

        foreach ($lines as [$line, $amount]) {
            SalesInvoiceLine::factory()->forDeliveryLine($line, $amount)->create(['sales_invoice_id' => $invoice->id]);
        }

        return app(PostSalesInvoice::class)->handle($invoice->refresh());
    }

    private function receive(SalesInvoice $invoice, string $amount): void
    {
        $receipt = CustomerReceipt::factory()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-09-12',
            'receipt_account_id' => Account::where('code', '1120')->value('id'),
            'amount' => $amount,
        ]);
        CustomerReceiptAllocation::factory()->create(['customer_receipt_id' => $receipt->id, 'sales_invoice_id' => $invoice->id, 'amount' => $amount]);

        app(PostCustomerReceipt::class)->handle($receipt->refresh());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recordReturn(array $overrides = []): SalesReturn
    {
        return app(RecordSalesReturn::class)->handle(array_merge([
            'return_date' => '2026-09-15',
            'delivery_line_id' => $this->line->id,
            'good_quantity' => '40',
            'damaged_quantity' => '10',
            'warehouse_id' => $this->main->id,
            'settlement' => 'CREDIT',
            'reason' => 'Some coconuts were spoiled',
        ], $overrides), User::factory()->create()->id);
    }

    private function balance(string $code): string
    {
        return app(AccountingBalanceService::class)->balance(Account::where('code', $code)->firstOrFail());
    }

    private function assertLedgerAgrees(): void
    {
        $this->assertSame(app(AccountsReceivableService::class)->totalOutstanding(), $this->balance('1210'));
        $this->assertTrue(app(AccountingBalanceService::class)->trialBalance()['balanced']);
    }

    #[Test]
    public function a_credit_reduces_the_invoice_good_goods_return_to_stock_and_damaged_ones_are_a_loss(): void
    {
        $stockBefore = app(StockQueryService::class)->quantityForProduct($this->products['COCONUT']->id);

        $return = $this->recordReturn();

        // 50 x Rs. 130 credited; the invoice is owed that much less.
        $this->assertSame('6500.000000', $return->credit_amount);
        $this->assertSame('253500.000000', app(AccountsReceivableService::class)->outstanding($this->invoice));

        // Only the 40 good coconuts come back, at the Rs. 102.50 they left with, into their lot.
        $this->assertSame((string) ($stockBefore + 40).'.000', app(StockQueryService::class)->quantityForProduct($this->products['COCONUT']->id));
        $this->assertSame('4100.000000', $return->good_cost);
        $this->assertSame('1025.000000', $return->damaged_cost);
        $this->assertSame($this->coconutLot->id, $return->inventoryTransaction->lines->first()->lot_id);

        // Dr revenue 6,500 / Cr receivables 6,500; Dr inventory 4,100 + loss 1,025 / Cr COGS 5,125.
        $this->assertSame('1025.000000', $this->balance('6900'));
        $this->assertLedgerAgrees();
    }

    #[Test]
    public function no_more_can_come_back_than_was_delivered_and_only_invoiced_goods_can(): void
    {
        $this->recordReturn(['good_quantity' => '1990', 'damaged_quantity' => '0']);

        try {
            $this->recordReturn(['good_quantity' => '11', 'damaged_quantity' => '0']);
            $this->fail('More came back than was delivered.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('good_quantity', $exception->errors());
        }

        $uninvoiced = $this->deliver('COCONUT', '100.000', '130.000000', $this->coconutLot)->lines->first();

        $this->expectException(ValidationException::class);
        $this->recordReturn(['delivery_line_id' => $uninvoiced->id, 'good_quantity' => '5']);
    }

    #[Test]
    public function money_back_is_limited_to_what_the_customer_paid(): void
    {
        $refund = ['settlement' => 'REFUND', 'cash_bank_account_id' => CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->value('id'), 'payment_method' => 'CASH'];

        try {
            $this->recordReturn($refund);
            $this->fail('Money was given back for an invoice nobody paid.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement', $exception->errors());
        }

        $this->receive($this->invoice, '100000.00');
        $cashBefore = $this->balance('1110');

        $return = $this->recordReturn($refund);

        // Money left the till; what the invoice is owed did not change.
        $this->assertSame(DecimalMath::cost(DecimalMath::sub($cashBefore, '6500')), $this->balance('1110'));
        $this->assertSame('160000.000000', app(AccountsReceivableService::class)->outstanding($this->invoice));
        $this->assertNull($return->credit_invoice_id);
        $this->assertLedgerAgrees();
    }

    #[Test]
    public function an_exchange_pays_the_invoice_for_the_replacement_goods(): void
    {
        $huskLot = $this->stockUp('RAW-HUSK', '5000.000', '4.000000');
        $huskLine = $this->deliver('RAW-HUSK', '650.000', '10.000000', $huskLot)->lines->first();
        $huskInvoice = $this->invoice([[$huskLine, '6500.000000']]);

        $this->recordReturn(['settlement' => 'EXCHANGE', 'credit_invoice_id' => $huskInvoice->id]);

        $this->assertSame('0.000000', app(AccountsReceivableService::class)->outstanding($huskInvoice));
        $this->assertSame(SalesInvoiceStatus::Paid, $huskInvoice->fresh()->status);
        $this->assertSame('260000.000000', app(AccountsReceivableService::class)->outstanding($this->invoice));
        $this->assertLedgerAgrees();
    }

    #[Test]
    public function a_reversal_undoes_the_return_and_blocks_nothing_afterwards(): void
    {
        $return = $this->recordReturn();

        // While the return stands, its invoice cannot be reversed.
        try {
            app(ReverseSalesInvoice::class)->handle($this->invoice);
            $this->fail('An invoice with a standing return was reversed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $stock = app(StockQueryService::class)->quantityForProduct($this->products['COCONUT']->id);
        $reversed = app(ReverseSalesReturn::class)->handle($return);

        $this->assertSame(SalesReturnStatus::Reversed, $reversed->status);
        $this->assertSame((string) ($stock - 40).'.000', app(StockQueryService::class)->quantityForProduct($this->products['COCONUT']->id));
        $this->assertSame('260000.000000', app(AccountsReceivableService::class)->outstanding($this->invoice));
        $this->assertSame('0.000000', $this->balance('6900'));
        $this->assertLedgerAgrees();

        $this->expectException(ValidationException::class);
        app(ReverseSalesReturn::class)->handle($reversed);
    }

    #[Test]
    public function the_screens_record_a_return_for_a_permitted_user(): void
    {
        $user = User::factory()->withPermissions(Permission::SalesView, Permission::SalesReturnRecord)->create();

        $this->actingAs($user)->get(route('sales-returns.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sales/returns/Create')
                ->where('lines.0.id', $this->line->id)
                ->where('lines.0.returnable', '2000.000')
                ->where('lines.0.invoice.id', $this->invoice->id));

        $this->actingAs($user)->post(route('sales-returns.store'), [
            'return_date' => '2026-09-15',
            'delivery_line_id' => $this->line->id,
            'good_quantity' => '5',
            'damaged_quantity' => '0',
            'warehouse_id' => $this->main->id,
            'settlement' => 'CREDIT',
            'credit_invoice_id' => $this->invoice->id,
            'reason' => 'Wrong size',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $return = SalesReturn::firstOrFail();
        $this->actingAs($user)->get(route('sales-returns.show', $return))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('salesReturn.credit_amount', '650.000000'));
        $this->actingAs($user)->get(route('sales-returns.index'))->assertOk();

        // Recording does not include reversing, and a credit amount is never accepted.
        $this->actingAs($user)->patch(route('sales-returns.reverse', $return))->assertForbidden();
        $this->actingAs($user)->post(route('sales-returns.store'), ['credit_amount' => '1'])->assertSessionHasErrors('credit_amount');
    }
}
