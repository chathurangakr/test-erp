<?php

namespace Tests\Feature\Receivables;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Receivables\RecordSalesCreditNote;
use App\Actions\Receivables\ReverseSalesCreditNote;
use App\Actions\Receivables\ReverseSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\Permission;
use App\Enums\PricingBasis;
use App\Enums\SalesCreditNoteStatus;
use App\Enums\SalesInvoiceStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\ProfitLossService;
use App\Services\Inventory\StockQueryService;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Discounts and quality allowances (2026-10-25): a credit note reduces what a customer owes on
 * one invoice — Dr sales discounts and allowances / Cr trade receivables — with no goods moving.
 * The receivables invariant must keep holding:
 *
 *   Trade Receivables general-ledger balance  ==  total invoice outstanding
 */
class SalesCreditNotesTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private SalesInvoice $invoice;

    private Product $coconut;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $nos->id]);
        $this->customer = Customer::factory()->create(['code' => 'CUS-001']);
        $main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seedProductionRecipes();
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);

        // 2,000 coconuts delivered at Rs. 130, invoiced for Rs. 260,000.
        $lot = app(CreateInventoryLot::class)->handle($this->coconut);
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine($this->coconut->id, $main->id, InventoryDirection::In, '10000.000', $lot->id, unitCost: '102.500000'),
        ]);
        $delivery = Delivery::factory()->create(['customer_id' => $this->customer->id, 'warehouse_id' => $main->id, 'delivery_date' => '2026-09-05']);
        $line = DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id, 'product_id' => $this->coconut->id, 'lot_id' => $lot->id,
            'quantity' => '2000.000', 'pricing_basis' => PricingBasis::Primary, 'unit_price' => '130.000000',
        ]);
        app(PostDelivery::class)->handle($delivery->refresh());

        $invoice = SalesInvoice::factory()->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-09-10', 'due_date' => '2026-09-25']);
        SalesInvoiceLine::factory()->forDeliveryLine($line->refresh(), '260000.000000')->create(['sales_invoice_id' => $invoice->id]);
        $this->invoice = app(PostSalesInvoice::class)->handle($invoice->refresh());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function credit(array $overrides = []): SalesCreditNote
    {
        return app(RecordSalesCreditNote::class)->handle(array_merge([
            'credit_date' => '2026-09-15',
            'sales_invoice_id' => $this->invoice->id,
            'kind' => 'ALLOWANCE',
            'amount' => '15000',
            'reason' => 'Copra moisture above the agreed level',
        ], $overrides));
    }

    private function assertReceivablesReconcile(): void
    {
        $ledger = app(AccountingBalanceService::class)->balance(Account::where('code', '1210')->firstOrFail());

        $this->assertSame(app(AccountsReceivableService::class)->totalOutstanding(), $ledger, 'Trade Receivables must equal what invoices are still owed.');
    }

    #[Test]
    public function an_allowance_reduces_what_the_invoice_is_owed_and_sales_with_no_stock_moving(): void
    {
        $stockBefore = app(StockQueryService::class)->quantityForProduct($this->coconut->id);
        $note = $this->credit();

        $this->assertStringStartsWith('CRN-20260915-', $note->credit_note_number);
        $this->assertSame('245000.000000', app(AccountsReceivableService::class)->outstanding($this->invoice->refresh()));
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $this->invoice->status);
        $this->assertSame($stockBefore, app(StockQueryService::class)->quantityForProduct($this->coconut->id));

        // The journal: Dr 4190 Sales Discounts and Allowances / Cr Trade Receivables.
        $lines = $note->journalEntry->lines->mapWithKeys(fn ($line) => [$line->account->code => [$line->debit, $line->credit]]);
        $this->assertSame(['15000.000000', '0.000000'], $lines['4190']);
        $this->assertSame(['0.000000', '15000.000000'], $lines['1210']);
        $this->assertReceivablesReconcile();

        // Sales revenue on the P&L is 260,000 less the allowance.
        $this->assertSame('245000.000000', app(ProfitLossService::class)->statement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))['revenue']['total']);
    }

    #[Test]
    public function never_more_than_the_invoice_still_owes_nor_before_it(): void
    {
        $receipt = CustomerReceipt::factory()->create([
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-09-12',
            'receipt_account_id' => Account::where('code', '1120')->value('id'), 'amount' => '250000',
        ]);
        CustomerReceiptAllocation::factory()->create(['customer_receipt_id' => $receipt->id, 'sales_invoice_id' => $this->invoice->id, 'amount' => '250000']);
        app(PostCustomerReceipt::class)->handle($receipt->refresh());

        $this->assertRefused(fn () => $this->credit(['amount' => '10000.01']), 'amount', 'owed only 10000.00');
        $this->assertRefused(fn () => $this->credit(['credit_date' => '2026-09-09']), 'credit_date', 'dated after this credit note');

        $this->credit(['amount' => '10000']);
        $this->assertSame(SalesInvoiceStatus::Paid, $this->invoice->refresh()->status);
        $this->assertRefused(fn () => $this->credit(['amount' => '1']), 'sales_invoice_id', 'not owed anything');
        $this->assertReceivablesReconcile();
    }

    #[Test]
    public function reversing_puts_the_amount_back_and_an_invoice_with_a_standing_note_cannot_be_reversed(): void
    {
        $note = $this->credit();

        $this->assertRefused(fn () => app(ReverseSalesInvoice::class)->handle($this->invoice->refresh()), 'status', 'Reverse the credit note first');

        app(ReverseSalesCreditNote::class)->handle($note);

        $this->assertSame(SalesCreditNoteStatus::Reversed, $note->refresh()->status);
        $this->assertSame('260000.000000', app(AccountsReceivableService::class)->outstanding($this->invoice->refresh()));
        $this->assertSame(SalesInvoiceStatus::Posted, $this->invoice->status);
        $this->assertReceivablesReconcile();
        $this->assertRefused(fn () => app(ReverseSalesCreditNote::class)->handle($note), 'status', 'already been reversed');
    }

    #[Test]
    public function the_screens_follow_the_permissions(): void
    {
        $clerk = User::factory()->withPermissions(Permission::ArView)->create();
        $note = $this->credit();

        $this->actingAs($clerk)->get(route('credit-notes.index'))->assertOk();
        $this->actingAs($clerk)->get(route('credit-notes.show', $note))->assertOk();
        $this->actingAs($clerk)->get(route('credit-notes.create'))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('credit-notes.create'))
            ->assertInertia(fn ($page) => $page->component('finance/credit-notes/Create')->where('invoices.0.outstanding', '245000.000000'));
        $this->actingAs($admin)->post(route('credit-notes.store'), [
            'credit_date' => '2026-09-16', 'sales_invoice_id' => $this->invoice->id, 'kind' => 'DISCOUNT', 'amount' => '5000', 'reason' => 'Agreed price',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, SalesCreditNote::count());
    }

    private function assertRefused(callable $call, string $field, string $message): void
    {
        try {
            $call();
            $this->fail("Expected a refusal on {$field}: {$message}");
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, (string) $exception->validator->errors()->first($field));
        }
    }
}
