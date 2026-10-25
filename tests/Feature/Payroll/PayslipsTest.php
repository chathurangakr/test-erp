<?php

namespace Tests\Feature\Payroll;

use App\Actions\Payroll\PayPayroll;
use App\Actions\Payroll\RecordAttendance;
use App\Actions\Payroll\RecordSalaryAdvance;
use App\Actions\Payroll\ReversePayroll;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\CashBankAccount;
use App\Models\Employee;
use App\Models\FiscalPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Payslips (2026-10-25): one per person paid, read from the payroll and the days it paid —
 * the days with their rates, earned, advance taken back, paid, and the advance still owed.
 */
class PayslipsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private PayrollRun $run;

    private Employee $kamal;

    private Employee $nimal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        AccountingSetting::query()->updateOrCreate([], [
            'wages_account_id' => Account::where('code', '6300')->value('id'),
            'employee_advances_account_id' => Account::where('code', '1220')->value('id'),
        ]);
        $cash = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $this->user = User::factory()->admin()->create();

        $this->kamal = Employee::factory()->create(['code' => 'EMP-0001', 'name' => 'Kamal', 'daily_rate' => '2000.00']);
        $this->nimal = Employee::factory()->create(['code' => 'EMP-0002', 'name' => 'Nimal', 'daily_rate' => '1500.00']);
        foreach (['2026-09-07' => 'FULL_DAY', '2026-09-08' => 'HALF_DAY', '2026-09-09' => 'LEAVE'] as $date => $status) {
            app(RecordAttendance::class)->handle($date, [['employee_id' => $this->kamal->id, 'status' => $status], ['employee_id' => $this->nimal->id, 'status' => 'FULL_DAY']], $this->user->id);
        }

        // Kamal took 5,000 in advance; 2,000 is taken back now, 3,000 stays owed.
        app(RecordSalaryAdvance::class)->handle(['employee_id' => $this->kamal->id, 'advance_date' => '2026-09-02', 'amount' => '5000', 'cash_bank_account_id' => $cash->id, 'payment_method' => 'CASH'], $this->user->id);
        $this->run = app(PayPayroll::class)->handle([
            'period_start' => '2026-09-07', 'period_end' => '2026-09-09', 'pay_date' => '2026-09-10',
            'deductions' => [['employee_id' => $this->kamal->id, 'amount' => '2000']],
            'cash_bank_account_id' => $cash->id, 'payment_method' => 'CASH',
        ], $this->user->id);
    }

    #[Test]
    public function each_slip_shows_the_days_the_pay_and_the_advance_still_owed(): void
    {
        $this->actingAs($this->user)->get(route('payrolls.payslips', $this->run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('payroll/payslips/Print')
                ->has('slips', 2)
                ->where('slips.0.name', 'Kamal')
                ->where('slips.0.days.0.date', '2026-09-07')
                ->where('slips.0.days.1.pay', '1000.000000')
                ->where('slips.0.gross_pay', '3000.00')
                ->where('slips.0.advances_deducted', '2000.00')
                ->where('slips.0.net_pay', '1000.00')
                ->where('slips.0.advance_still_owed', '3000.000000')
                ->where('slips.1.name', 'Nimal')
                ->where('slips.1.net_pay', '4500.00'));
    }

    #[Test]
    public function one_persons_slip_and_a_pdf(): void
    {
        $this->actingAs($this->user)->get(route('payrolls.payslips', ['payroll' => $this->run, 'employee_id' => $this->nimal->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('slips', 1)->where('slips.0.code', 'EMP-0002'));

        $pdf = $this->actingAs($this->user)->get(route('payrolls.payslips', ['payroll' => $this->run, 'employee_id' => $this->nimal->id, 'format' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
        $this->assertStringContainsString('EMP-0002', (string) $pdf->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_reversed_payroll_is_marked_and_only_payroll_viewers_may_see_slips(): void
    {
        app(ReversePayroll::class)->handle($this->run, $this->user->id);
        $this->actingAs($this->user)->get(route('payrolls.payslips', $this->run))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('slips.0.reversed', true));

        $this->actingAs(User::factory()->withPermissions(Permission::PayrollView)->create())->get(route('payrolls.payslips', $this->run))->assertOk();
        $this->actingAs(User::factory()->withPermissions(Permission::FinanceView)->create())->get(route('payrolls.payslips', $this->run))->assertForbidden();
    }
}
