<?php

namespace Tests\Feature\Loans;

use App\Actions\Loans\RecordLoan;
use App\Actions\Loans\RecordLoanPayment;
use App\Actions\Loans\ReverseLoan;
use App\Actions\Loans\ReverseLoanPayment;
use App\Actions\Loans\UpdateLoan;
use App\Enums\LoanDocumentStatus;
use App\Enums\Permission;
use App\Mail\LoanReminder;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Models\Loan;
use App\Models\User;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Loans\LoanBalances;
use App\Support\DecimalMath;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Loans the factory owes (owner's rules, 2026-10-24): installments vary, each payment records
 * the interest from the bank's slip, machinery loans carry none, and administrators are
 * reminded 7 days before, 2 days before and on the due day. The invariant:
 *
 *   Loans Payable general-ledger balance  ==  Σ what each loan still owes
 */
class LoansTest extends TestCase
{
    use RefreshDatabase;

    private CashBankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        FiscalPeriod::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $this->bank = CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function loan(array $overrides = []): Loan
    {
        return app(RecordLoan::class)->handle(array_merge([
            'lender' => 'People\'s Bank',
            'start_date' => '2026-09-01',
            'funding' => 'EXISTING',
            'amount' => '500000',
            'has_interest' => true,
            'installment_amount' => '25000',
            'due_day' => 25,
        ], $overrides), User::factory()->create()->id);
    }

    private function pay(Loan $loan, string $amount, string $interest = '0', string $date = '2026-09-25'): mixed
    {
        return app(RecordLoanPayment::class)->handle($loan, [
            'payment_date' => $date,
            'amount' => $amount,
            'interest_amount' => $interest,
            'cash_bank_account_id' => $this->bank->id,
            'payment_method' => 'BANK_TRANSFER',
        ]);
    }

    private function balance(string $code): string
    {
        return app(AccountingBalanceService::class)->balance(Account::where('code', $code)->firstOrFail());
    }

    private function assertLoansAgree(): void
    {
        $owed = Loan::all()->reduce(fn (string $sum, Loan $loan): string => DecimalMath::add($sum, app(LoanBalances::class)->outstanding($loan)), DecimalMath::zero());
        $this->assertSame(DecimalMath::cost($owed), $this->balance('2400'));
    }

    #[Test]
    public function a_running_loan_comes_in_at_what_is_still_owed_and_a_new_one_into_the_bank(): void
    {
        $this->loan();
        $bankBefore = $this->balance('1120');
        $this->loan(['lender' => 'HNB', 'funding' => 'RECEIVED', 'amount' => '200000', 'cash_bank_account_id' => $this->bank->id]);

        $this->assertSame('700000.000000', $this->balance('2400'));
        // Opening Balance Equity is debited, so its credit-natured balance shows negative.
        $this->assertSame('-500000.000000', $this->balance('3300'));
        $this->assertSame(DecimalMath::cost(DecimalMath::add($bankBefore, '200000')), $this->balance('1120'));
        $this->assertLoansAgree();
    }

    #[Test]
    public function a_payment_splits_into_interest_and_what_reduces_the_loan_whatever_was_paid(): void
    {
        $loan = $this->loan();

        // More than the usual 25,000 this month; 4,200 of it was interest.
        $payment = $this->pay($loan, '30000', '4200');

        $this->assertSame('25800.000000', $payment->principal_amount);
        $this->assertSame('474200.000000', app(LoanBalances::class)->outstanding($loan));
        $this->assertSame('4200.000000', $this->balance('6950'));
        $this->assertLoansAgree();

        // Less than usual the next month is fine too.
        $this->pay($loan, '10000', '3000', '2026-10-25');
        $this->assertSame('467200.000000', app(LoanBalances::class)->outstanding($loan));
    }

    #[Test]
    public function a_payment_cannot_take_the_loan_below_zero_and_an_interest_free_loan_takes_no_interest(): void
    {
        $machine = $this->loan(['lender' => 'Machine seller', 'amount' => '60000', 'has_interest' => false]);

        try {
            $this->pay($machine, '10000', '500');
            $this->fail('Interest was recorded on an interest-free loan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('interest_amount', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $this->pay($machine, '60000.01');
    }

    #[Test]
    public function reversals_put_the_money_back_and_a_loan_with_payments_cannot_be_reversed(): void
    {
        $loan = $this->loan();
        $payment = $this->pay($loan, '25000', '5000');

        try {
            app(ReverseLoan::class)->handle($loan);
            $this->fail('A loan with payments was reversed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        app(ReverseLoanPayment::class)->handle($payment);
        $this->assertSame('500000.000000', app(LoanBalances::class)->outstanding($loan));

        $reversed = app(ReverseLoan::class)->handle($loan);
        $this->assertSame(LoanDocumentStatus::Reversed, $reversed->status);
        $this->assertSame('0.000000', $this->balance('2400'));
        $this->assertLoansAgree();
    }

    #[Test]
    public function the_yearly_rate_suggests_each_months_interest_on_what_is_still_owed(): void
    {
        $loan = $this->loan(['interest_rate' => '18']);
        $balances = app(LoanBalances::class);

        // 500,000 × 18% ÷ 12 = 7,500.
        $this->assertSame('7500.000000', $balances->suggestedInterest($loan));

        // Paid 25,000 of which 7,500 interest: 482,500 still owed → 7,237.50 next month.
        $this->pay($loan, '25000', '7500');
        $this->assertSame('7237.500000', $balances->suggestedInterest($loan->refresh()));

        // The rate can change (the bank changes it); no rate or no interest suggests nothing.
        app(UpdateLoan::class)->handle($loan, ['lender' => $loan->lender, 'has_interest' => true, 'interest_rate' => '12']);
        $this->assertSame('4825.000000', $balances->suggestedInterest($loan->refresh()));
        $this->assertNull($balances->suggestedInterest($this->loan()));
        $this->assertNull($balances->suggestedInterest($this->loan(['has_interest' => false, 'interest_rate' => '18'])));

        $this->actingAs(User::factory()->admin()->create())->get(route('loans.show', $loan))
            ->assertInertia(fn ($page) => $page->where('loan.interest_rate', '12.0000')->where('loan.suggested_interest', '4825.000000'));
    }

    #[Test]
    public function only_the_description_and_reminder_details_can_be_edited(): void
    {
        $loan = $this->loan();

        $loan->update(['installment_amount' => '27500', 'due_day' => 5]);
        $this->assertSame(5, $loan->fresh()->due_day);

        $this->expectException(RuntimeException::class);
        $loan->update(['amount' => '1']);
    }

    #[Test]
    public function the_next_due_date_follows_the_due_day_even_in_a_short_month(): void
    {
        $balances = app(LoanBalances::class);
        $loan = $this->loan(['due_day' => 31]);

        $this->assertSame('2026-09-30', $balances->nextDueDate($loan, Carbon::parse('2026-09-20')->toImmutable())->toDateString());
        $this->assertSame('2026-10-31', $balances->nextDueDate($loan, Carbon::parse('2026-10-01')->toImmutable())->toDateString());
    }

    #[Test]
    public function administrators_are_reminded_seven_and_two_days_before_and_on_the_day_but_once_and_not_once_paid(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $other = User::factory()->withPermissions(Permission::LoansView)->create();
        $loan = $this->loan();   // due on the 25th

        foreach (['2026-09-17' => 0, '2026-09-18' => 1, '2026-09-23' => 2, '2026-09-25' => 3] as $day => $expected) {
            $this->travelTo(Carbon::parse("{$day} 03:00:00", 'UTC'));
            $this->artisan('loans:remind')->assertSuccessful();
            $this->artisan('loans:remind')->assertSuccessful(); // a second run sends nothing more
            Mail::assertSent(LoanReminder::class, $expected);
        }

        Mail::assertSent(LoanReminder::class, fn (LoanReminder $mail): bool => $mail->hasTo($admin->email));
        Mail::assertNotSent(LoanReminder::class, fn (LoanReminder $mail): bool => $mail->hasTo($other->email));

        // October's installment paid early: no reminder for it.
        $this->pay($loan, '25000', '4000', '2026-10-10');
        $this->travelTo(Carbon::parse('2026-10-18 03:00:00', 'UTC'));
        $this->artisan('loans:remind')->assertSuccessful();
        Mail::assertSent(LoanReminder::class, 3);
    }

    #[Test]
    public function the_reminder_email_reads_clearly_in_html_and_text(): void
    {
        $mail = new LoanReminder([
            ['loan_number' => 'LOAN-1', 'lender' => 'People\'s Bank', 'due_date' => '2026-09-25', 'days_before' => 0, 'installment' => '25000.000000', 'outstanding' => '475000.000000', 'url' => 'https://example.test/loans/1'],
            ['loan_number' => 'LOAN-2', 'lender' => 'Machine seller', 'due_date' => '2026-09-30', 'days_before' => 7, 'installment' => null, 'outstanding' => '60000.000000', 'url' => 'https://example.test/loans/2'],
        ]);

        $mail->assertSeeInHtml('Due today — 2026-09-25');
        $mail->assertSeeInHtml('Due in 7 days — 2026-09-30');
        $mail->assertSeeInHtml('25,000.00');
        $mail->assertSeeInText('Machine seller (LOAN-2)');
        $this->assertSame('2 loan installments due soon', $mail->envelope()->subject);
    }

    #[Test]
    public function the_screens_add_a_loan_and_record_a_payment_for_permitted_users(): void
    {
        $user = User::factory()->withPermissions(Permission::LoansView, Permission::LoansManage, Permission::LoansPaymentRecord)->create();

        $this->actingAs($user)->post(route('loans.store'), [
            'lender' => 'Sampath Bank',
            'start_date' => '2026-09-01',
            'funding' => 'EXISTING',
            'amount' => '120000',
            'installment_amount' => '10000',
            'due_day' => 10,
            'has_interest' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $loan = Loan::firstOrFail();
        $this->actingAs($user)->post(route('loans.payments.store', $loan), [
            'payment_date' => '2026-09-10',
            'amount' => '10000',
            'interest_amount' => '1500',
            'cash_bank_account_id' => $this->bank->id,
            'payment_method' => 'BANK_TRANSFER',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('loans/Show')
                ->where('loan.outstanding', '111500.000000')
                ->has('loan.payments', 1));
        $this->actingAs($user)->get(route('loans.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.outstanding', '111500.000000'));

        // Recording does not include reversing.
        $this->actingAs($user)->patch(route('loans.reverse', $loan))->assertForbidden();
        $this->actingAs(User::factory()->withPermissions(Permission::LoansView)->create())->get(route('loans.create'))->assertForbidden();
    }
}
