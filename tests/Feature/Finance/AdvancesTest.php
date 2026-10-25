<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Payables\ApplySupplierAdvance;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Payables\RecordSupplierAdvance;
use App\Actions\Payables\RefundSupplierAdvance;
use App\Actions\Payables\ReverseSupplierAdvance;
use App\Actions\Payables\ReverseSupplierAdvanceApplication;
use App\Actions\Payables\ReverseSupplierInvoice;
use App\Actions\Receivables\ApplyCustomerAdvance;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Receivables\RecordCustomerAdvance;
use App\Actions\Receivables\RefundCustomerAdvance;
use App\Actions\Receivables\ReverseCustomerAdvance;
use App\Actions\Receivables\ReverseCustomerAdvanceApplication;
use App\Actions\Receivables\ReverseCustomerAdvanceRefund;
use App\Actions\Receivables\ReverseSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\Permission;
use App\Enums\PricingBasis;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\AdvanceBalances;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Customer and supplier advances (2026-10-25): money received or paid before the invoice, held
 * in a control account and set against invoices later. The invariants must keep holding:
 *
 *   Customer Advances (2130) == unapplied customer advances · Trade Receivables == invoice outstanding
 *   Supplier Advances (1230) == unapplied supplier advances · Trade Payables == invoice outstanding
 */
class AdvancesTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Supplier $supplier;

    private CashBankAccount $bank;

    private SalesInvoice $salesInvoice;

    private SupplierInvoice $supplierInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProductionRecipes();
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);
        $this->bank = CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();

        $this->customer = Customer::factory()->create(['code' => 'CUS-001']);
        $this->supplier = Supplier::factory()->create(['code' => 'SUP-001']);

        // A customer invoice of 260,000 for delivered coconuts.
        $coconut = Product::query()->where('code', 'COCONUT')->first()
            ?? Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => UnitOfMeasure::factory()->create(['code' => 'NOS'])->id]);
        $main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine($coconut->id, $main->id, InventoryDirection::In, '10000.000', unitCost: '102.500000'),
        ]);
        $delivery = Delivery::factory()->create(['customer_id' => $this->customer->id, 'warehouse_id' => $main->id, 'delivery_date' => '2026-09-05']);
        $line = DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id, 'product_id' => $coconut->id, 'lot_id' => null,
            'quantity' => '2000.000', 'pricing_basis' => PricingBasis::Primary, 'unit_price' => '130.000000',
        ]);
        app(PostDelivery::class)->handle($delivery->refresh());
        $invoice = SalesInvoice::factory()->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-09-10', 'due_date' => '2026-09-25']);
        SalesInvoiceLine::factory()->forDeliveryLine($line->refresh(), '260000.000000')->create(['sales_invoice_id' => $invoice->id]);
        $this->salesInvoice = app(PostSalesInvoice::class)->handle($invoice->refresh());

        // A supplier invoice of 80,000 for transport, charged straight to an expense.
        $supplierInvoice = SupplierInvoice::factory()->create(['supplier_id' => $this->supplier->id, 'invoice_date' => '2026-09-12']);
        SupplierInvoiceLine::factory()->directExpense(Account::where('code', '6400')->value('id'), '80000')->create(['supplier_invoice_id' => $supplierInvoice->id]);
        $this->supplierInvoice = app(PostSupplierInvoice::class)->handle($supplierInvoice->refresh());
    }

    private function balance(string $code): string
    {
        return app(AccountingBalanceService::class)->balance(Account::where('code', $code)->firstOrFail());
    }

    private function assertInvariantsHold(): void
    {
        $advances = app(AdvanceBalances::class);

        $this->assertSame($advances->customerTotal(), $this->balance('2130'), 'Customer Advances == unapplied customer advances');
        $this->assertSame($advances->supplierTotal(), $this->balance('1230'), 'Supplier Advances == unapplied supplier advances');
        $this->assertSame(app(AccountsReceivableService::class)->totalOutstanding(), $this->balance('1210'), 'Trade Receivables == outstanding');
        $this->assertSame(app(AccountsPayableService::class)->totalOutstanding(), $this->balance('2110'), 'Trade Payables == outstanding');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function customerAdvance(array $overrides = []): CustomerAdvance
    {
        return app(RecordCustomerAdvance::class)->handle(array_merge([
            'customer_id' => $this->customer->id, 'advance_date' => '2026-09-02', 'amount' => '100000',
            'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER',
        ], $overrides));
    }

    #[Test]
    public function a_customer_advance_is_held_then_settles_their_invoice(): void
    {
        $bankBefore = $this->balance('1120');
        $advance = $this->customerAdvance();

        $this->assertStringStartsWith('CADV-20260902-', $advance->advance_number);
        $this->assertSame('100000.000000', $this->balance('2130'));
        $this->assertSame(bcadd($bankBefore, '100000', 6), $this->balance('1120'));
        $this->assertInvariantsHold();

        app(ApplyCustomerAdvance::class)->handle($advance, ['sales_invoice_id' => $this->salesInvoice->id, 'amount' => '60000', 'application_date' => '2026-09-11']);

        $this->assertSame('40000.000000', app(AdvanceBalances::class)->remaining($advance));
        $this->assertSame('200000.000000', app(AccountsReceivableService::class)->outstanding($this->salesInvoice->refresh()));
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $this->salesInvoice->status);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function an_application_is_capped_dated_and_kept_to_the_same_customer(): void
    {
        $advance = $this->customerAdvance(['amount' => '300000']);
        $apply = fn (array $data) => app(ApplyCustomerAdvance::class)->handle($advance, array_merge(['sales_invoice_id' => $this->salesInvoice->id, 'amount' => '1000', 'application_date' => '2026-09-11'], $data));

        $this->assertRefused(fn () => $apply(['amount' => '260000.01']), 'amount', 'owed only 260,000.00');
        $this->assertRefused(fn () => $apply(['application_date' => '2026-09-09']), 'application_date', 'on or after both');

        $other = SalesInvoice::factory()->create(['customer_id' => Customer::factory()->create()->id, 'status' => SalesInvoiceStatus::Posted]);
        $this->assertRefused(fn () => $apply(['sales_invoice_id' => $other->id]), 'sales_invoice_id', 'of this customer');

        $small = $this->customerAdvance(['amount' => '500']);
        $this->assertRefused(fn () => app(ApplyCustomerAdvance::class)->handle($small, ['sales_invoice_id' => $this->salesInvoice->id, 'amount' => '501', 'application_date' => '2026-09-11']), 'amount', 'Only 500.00 is left');
    }

    #[Test]
    public function reversals_undo_in_order_and_an_applied_invoice_cannot_be_reversed(): void
    {
        $advance = $this->customerAdvance();
        $application = app(ApplyCustomerAdvance::class)->handle($advance, ['sales_invoice_id' => $this->salesInvoice->id, 'amount' => '60000', 'application_date' => '2026-09-11']);

        $this->assertRefused(fn () => app(ReverseCustomerAdvance::class)->handle($advance), 'status', 'Reverse those applications first');
        $this->assertRefused(fn () => app(ReverseSalesInvoice::class)->handle($this->salesInvoice->refresh()), 'status', 'customer advance applied');

        app(ReverseCustomerAdvanceApplication::class)->handle($application);
        $this->assertSame('260000.000000', app(AccountsReceivableService::class)->outstanding($this->salesInvoice->refresh()));
        $this->assertSame(SalesInvoiceStatus::Posted, $this->salesInvoice->status);

        app(ReverseCustomerAdvance::class)->handle($advance);
        $this->assertSame('0.000000', $this->balance('2130'));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function a_supplier_advance_is_held_then_settles_their_invoice(): void
    {
        $advance = app(RecordSupplierAdvance::class)->handle([
            'supplier_id' => $this->supplier->id, 'advance_date' => '2026-09-03', 'amount' => '50000',
            'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'CHEQUE',
            'cheque_number' => '000123', 'cheque_date' => '2026-09-03', 'cheque_bank' => 'BOC',
        ]);

        $this->assertStringStartsWith('SADV-20260903-', $advance->advance_number);
        $this->assertSame('000123', $advance->cheque_number);
        $this->assertSame('50000.000000', $this->balance('1230'));

        $application = app(ApplySupplierAdvance::class)->handle($advance, ['supplier_invoice_id' => $this->supplierInvoice->id, 'amount' => '50000', 'application_date' => '2026-09-12']);

        $this->assertSame('30000.000000', app(AccountsPayableService::class)->outstanding($this->supplierInvoice->refresh()));
        $this->assertSame(SupplierInvoiceStatus::PartiallyPaid, $this->supplierInvoice->status);
        $this->assertSame('0.000000', $this->balance('1230'));
        $this->assertInvariantsHold();

        $this->assertRefused(fn () => app(ReverseSupplierInvoice::class)->handle($this->supplierInvoice->refresh()), 'status', 'supplier advance applied');
        app(ReverseSupplierAdvanceApplication::class)->handle($application);
        app(ReverseSupplierAdvance::class)->handle($advance);
        $this->assertSame('80000.000000', app(AccountsPayableService::class)->outstanding($this->supplierInvoice->refresh()));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function what_is_left_of_an_advance_can_be_given_back_and_the_refund_reversed(): void
    {
        $advance = $this->customerAdvance();
        app(ApplyCustomerAdvance::class)->handle($advance, ['sales_invoice_id' => $this->salesInvoice->id, 'amount' => '60000', 'application_date' => '2026-09-11']);
        $bankBefore = $this->balance('1120');
        $refund = fn (array $data) => app(RefundCustomerAdvance::class)->handle($advance, array_merge([
            'refund_date' => '2026-09-15', 'amount' => '40000', 'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER',
        ], $data));

        $this->assertRefused(fn () => $refund(['amount' => '40000.01']), 'amount', 'Only 40,000.00 is left');
        $this->assertRefused(fn () => $refund(['refund_date' => '2026-09-01']), 'refund_date', 'on or after the advance');
        $this->assertRefused(fn () => $refund(['payment_method' => 'CASH']), 'cash_bank_account_id', 'Cash');

        $given = $refund([]);
        $lines = $given->journalEntry->lines->mapWithKeys(fn ($line) => [$line->account->code => [$line->debit, $line->credit]]);
        $this->assertSame(['40000.000000', '0.000000'], $lines['2130']);
        $this->assertSame(['0.000000', '40000.000000'], $lines['1120']);
        $this->assertSame('0.000000', app(AdvanceBalances::class)->remaining($advance));
        $this->assertSame(bcsub($bankBefore, '40000', 6), $this->balance('1120'));
        $this->assertInvariantsHold();
        $this->assertRefused(fn () => app(ReverseCustomerAdvance::class)->handle($advance), 'status', 'Reverse');

        app(ReverseCustomerAdvanceRefund::class)->handle($given);
        $this->assertSame('40000.000000', app(AdvanceBalances::class)->remaining($advance));
        $this->assertSame($bankBefore, $this->balance('1120'));
        $this->assertRefused(fn () => app(ReverseCustomerAdvanceRefund::class)->handle($given), 'status', 'already been reversed');
        $this->assertInvariantsHold();
    }

    #[Test]
    public function a_supplier_returns_what_is_left_of_their_advance(): void
    {
        $advance = app(RecordSupplierAdvance::class)->handle([
            'supplier_id' => $this->supplier->id, 'advance_date' => '2026-09-03', 'amount' => '50000',
            'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER',
        ]);
        $bankBefore = $this->balance('1120');

        $returned = app(RefundSupplierAdvance::class)->handle($advance, [
            'refund_date' => '2026-09-20', 'amount' => '20000', 'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER', 'reference' => 'Order cancelled',
        ]);

        $lines = $returned->journalEntry->lines->mapWithKeys(fn ($line) => [$line->account->code => [$line->debit, $line->credit]]);
        $this->assertSame(['20000.000000', '0.000000'], $lines['1120']);
        $this->assertSame(['0.000000', '20000.000000'], $lines['1230']);
        $this->assertSame('30000.000000', app(AdvanceBalances::class)->remaining($advance));
        $this->assertSame(bcadd($bankBefore, '20000', 6), $this->balance('1120'));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function the_screens_follow_the_permissions(): void
    {
        $advance = $this->customerAdvance();
        $viewer = User::factory()->withPermissions(Permission::ArView)->create();

        $this->actingAs($viewer)->get(route('customer-advances.index'))->assertOk();
        $this->actingAs($viewer)->get(route('customer-advances.show', $advance))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('advance.remaining', '100000.000000')->where('invoices.0.outstanding', '260000.000000'));
        $this->actingAs($viewer)->get(route('customer-advances.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('customer-advances.apply', $advance), ['invoice_id' => $this->salesInvoice->id, 'amount' => '1', 'application_date' => '2026-09-11'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('customer-advances.apply', $advance), ['invoice_id' => $this->salesInvoice->id, 'amount' => '1000', 'application_date' => '2026-09-11'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('supplier-advances.store'), [
            'supplier_id' => $this->supplier->id, 'advance_date' => '2026-09-04', 'amount' => '2500',
            'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs($viewer)->post(route('customer-advances.refund', $advance), ['refund_date' => '2026-09-15', 'amount' => '1', 'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER'])->assertForbidden();
        $this->actingAs($admin)->post(route('customer-advances.refund', $advance), ['refund_date' => '2026-09-15', 'amount' => '500', 'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER'])
            ->assertSessionHasNoErrors();
        $this->actingAs($viewer)->get(route('customer-advances.show', $advance))
            ->assertInertia(fn ($page) => $page->where('advance.remaining', '98500.000000')->has('advance.refunds', 1)->where('advance.can_reverse', false));
        $this->assertInvariantsHold();
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
