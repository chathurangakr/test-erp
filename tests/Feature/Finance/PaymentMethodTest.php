<?php

namespace Tests\Feature\Finance;

use App\Actions\Payables\PostSupplierPayment;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\FiscalPeriod;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cash, cheque or bank transfer on supplier payments and customer receipts.
 *
 * Cash goes through a cash account and a cheque or transfer through a bank account; a cheque
 * carries its number, date and bank; "credit" is no method at all — it is an invoice left
 * outstanding. Checked by the request for a clear message and again at posting.
 * See docs/ACCOUNTING_RULES.md §29.
 */
class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);

        $this->supplier = Supplier::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function paymentPayload(array $extra = []): array
    {
        $invoice = SupplierInvoice::factory()->create(['supplier_id' => $this->supplier->id]);

        return array_merge([
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-09-20',
            'payment_account_id' => $this->account('1110')->id,
            'amount' => '1000',
            'payment_method' => 'CASH',
            'allocations' => [['supplier_invoice_id' => $invoice->id, 'amount' => '1000']],
        ], $extra);
    }

    #[Test]
    public function a_payment_must_say_how_it_was_paid(): void
    {
        $this->post(route('supplier-payments.store'), $this->paymentPayload(['payment_method' => null]))
            ->assertSessionHasErrors('payment_method');

        // Credit is not a way of paying.
        $this->post(route('supplier-payments.store'), $this->paymentPayload(['payment_method' => 'CREDIT']))
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, SupplierPayment::count());
    }

    #[Test]
    public function cash_goes_through_a_till_and_a_cheque_or_transfer_through_a_bank(): void
    {
        $this->post(route('supplier-payments.store'), $this->paymentPayload([
            'payment_account_id' => $this->account('1120')->id,
        ]))->assertSessionHasErrors('payment_account_id');

        $this->post(route('supplier-payments.store'), $this->paymentPayload([
            'payment_method' => 'BANK_TRANSFER',
        ]))->assertSessionHasErrors('payment_account_id');

        $this->assertSame(0, SupplierPayment::count());

        $this->post(route('supplier-payments.store'), $this->paymentPayload([
            'payment_method' => 'BANK_TRANSFER',
            'payment_account_id' => $this->account('1120')->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(PaymentMethod::BankTransfer, SupplierPayment::query()->firstOrFail()->payment_method);
    }

    #[Test]
    public function a_cheque_needs_its_number_date_and_bank(): void
    {
        $this->post(route('supplier-payments.store'), $this->paymentPayload([
            'payment_method' => 'CHEQUE',
            'payment_account_id' => $this->account('1120')->id,
        ]))->assertSessionHasErrors(['cheque_number', 'cheque_date', 'cheque_bank']);

        $this->post(route('supplier-payments.store'), $this->paymentPayload([
            'payment_method' => 'CHEQUE',
            'payment_account_id' => $this->account('1120')->id,
            'cheque_number' => '004512',
            'cheque_date' => '2026-09-25',
            'cheque_bank' => 'Sampath Bank',
        ]))->assertSessionHasNoErrors();

        $payment = SupplierPayment::query()->firstOrFail();
        $this->assertSame(PaymentMethod::Cheque, $payment->payment_method);
        $this->assertSame('004512', $payment->cheque_number);
        $this->assertSame('2026-09-25', $payment->cheque_date->toDateString());
        $this->assertSame('Sampath Bank', $payment->cheque_bank);
    }

    #[Test]
    public function cheque_details_typed_for_cash_are_not_kept(): void
    {
        $this->post(route('supplier-payments.store'), $this->paymentPayload([
            'cheque_number' => '999',
            'cheque_date' => '2026-09-25',
            'cheque_bank' => 'Somewhere',
        ]))->assertSessionHasNoErrors();

        $payment = SupplierPayment::query()->firstOrFail();
        $this->assertSame(PaymentMethod::Cash, $payment->payment_method);
        $this->assertNull($payment->cheque_number);
        $this->assertNull($payment->cheque_date);
        $this->assertNull($payment->cheque_bank);
    }

    #[Test]
    public function a_customer_cheque_is_recorded_on_the_receipt(): void
    {
        $invoice = SalesInvoice::factory()->create(['customer_id' => $this->customer->id]);

        $this->post(route('customer-receipts.store'), [
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-09-20',
            'receipt_account_id' => $this->account('1120')->id,
            'amount' => '500',
            'payment_method' => 'CHEQUE',
            'cheque_number' => '771',
            'cheque_date' => '2026-09-20',
            'cheque_bank' => 'People\'s Bank',
            'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => '500']],
        ])->assertSessionHasNoErrors();

        $receipt = CustomerReceipt::query()->firstOrFail();
        $this->assertSame(PaymentMethod::Cheque, $receipt->payment_method);
        $this->assertSame('771', $receipt->cheque_number);

        $this->get(route('customer-receipts.show', $receipt))
            ->assertInertia(fn (Assert $page) => $page
                ->where('receipt.payment_method', 'CHEQUE')
                ->where('receipt.cheque_number', '771')
                ->where('receipt.cheque_bank', 'People\'s Bank'));
    }

    #[Test]
    public function posting_rechecks_the_method_against_the_account(): void
    {
        // An open period, so posting reaches the account and cheque checks.
        FiscalPeriod::factory()->create(['name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);

        $payment = SupplierPayment::factory()->create([
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-09-20',
            'payment_account_id' => $this->account('1120')->id,
            'payment_method' => PaymentMethod::Cash,
        ]);

        try {
            app(PostSupplierPayment::class)->handle($payment);
            $this->fail('Expected cash through a bank account to be refused at posting.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_account_id', $e->errors());
        }

        $receipt = CustomerReceipt::factory()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-09-20',
            'receipt_account_id' => $this->account('1120')->id,
            'payment_method' => PaymentMethod::Cheque,
            'cheque_number' => null,
        ]);

        try {
            app(PostCustomerReceipt::class)->handle($receipt);
            $this->fail('Expected a cheque without details to be refused at posting.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cheque_number', $e->errors());
        }
    }

    #[Test]
    public function the_payment_screen_offers_the_methods_and_can_start_from_an_invoice(): void
    {
        $invoice = SupplierInvoice::factory()->create(['supplier_id' => $this->supplier->id]);

        $this->get(route('supplier-payments.create', ['supplier_id' => $this->supplier->id, 'invoice_id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoiceId', $invoice->id)
                ->where('paymentMethods', fn ($methods) => collect($methods)->pluck('value')->all() === ['CASH', 'CHEQUE', 'BANK_TRANSFER']
                    && collect($methods)->firstWhere('value', 'CASH')['account_kind'] === 'CASH'
                    && collect($methods)->firstWhere('value', 'CHEQUE')['account_kind'] === 'BANK')
                ->where('paymentAccounts', fn ($accounts) => collect($accounts)->pluck('account_kind')->sort()->values()->all() === ['BANK', 'CASH']));

        $this->get(route('customer-receipts.create', ['customer_id' => $this->customer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoiceId', null)
                ->has('paymentMethods', 3));
    }
}
