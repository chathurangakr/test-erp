<?php

namespace Tests\Feature\Payroll;

use App\Actions\Payroll\PayPayroll;
use App\Actions\Payroll\RecordAttendance;
use App\Actions\Payroll\RecordSalaryAdvance;
use App\Actions\Payroll\ReversePayroll;
use App\Actions\Payroll\ReverseSalaryAdvance;
use App\Enums\PayrollDocumentStatus;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\CashBankAccount;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\FiscalPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Payroll\PayrollCalculator;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Payroll: daily attendance, salary advances and monthly salaries.
 *
 *   gross = full days × rate + half days × rate ÷ 2 (leave pays nothing)
 *   net   = gross − every advance still owed (up to the gross; the rest carries on)
 *
 * And the invariant that keeps it honest: the Employee Advances balance in the general ledger
 * always equals what employees still owe.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private CashBankAccount $cash;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);

        foreach ([['August 2026', '2026-08-01', '2026-08-31'], ['September 2026', '2026-09-01', '2026-09-30'], ['October 2026', '2026-10-01', '2026-10-31']] as [$name, $start, $end]) {
            FiscalPeriod::factory()->create(['name' => $name, 'start_date' => $start, 'end_date' => $end]);
        }

        AccountingSetting::query()->updateOrCreate([], [
            'wages_account_id' => $this->account('6300')->id,
            'employee_advances_account_id' => $this->account('1220')->id,
        ]);

        $this->cash = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $this->user = User::factory()->admin()->create();
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function balance(string $code): string
    {
        return app(AccountingBalanceService::class)->netBalance($this->account($code)->id);
    }

    /**
     * @param  array<string, string>  $days  date => FULL_DAY | HALF_DAY | LEAVE
     */
    private function attend(Employee $employee, array $days): void
    {
        foreach ($days as $date => $status) {
            app(RecordAttendance::class)->handle($date, [['employee_id' => $employee->id, 'status' => $status]], $this->user->id);
        }
    }

    private function advance(Employee $employee, string $date, string $amount): void
    {
        app(RecordSalaryAdvance::class)->handle([
            'employee_id' => $employee->id,
            'advance_date' => $date,
            'amount' => $amount,
            'cash_bank_account_id' => $this->cash->id,
            'payment_method' => 'CASH',
        ], $this->user->id);
    }

    private function pay(string $month, string $payDate): PayrollRun
    {
        return app(PayPayroll::class)->handle([
            'month' => $month,
            'pay_date' => $payDate,
            'cash_bank_account_id' => $this->cash->id,
            'payment_method' => 'CASH',
        ], $this->user->id);
    }

    #[Test]
    public function full_days_pay_the_rate_half_days_half_of_it_and_leave_nothing(): void
    {
        $kamal = Employee::factory()->create(['name' => 'Kamal', 'daily_rate' => '2000.00']);
        $nimal = Employee::factory()->create(['name' => 'Nimal', 'daily_rate' => '1555.55']);

        $this->attend($kamal, [
            '2026-08-03' => 'FULL_DAY', '2026-08-04' => 'FULL_DAY', '2026-08-05' => 'FULL_DAY',
            '2026-08-06' => 'HALF_DAY', '2026-08-07' => 'HALF_DAY', '2026-08-08' => 'LEAVE',
        ]);
        $this->attend($nimal, ['2026-08-03' => 'HALF_DAY']);

        $run = $this->pay('2026-08', '2026-09-05');

        $lines = $run->lines->keyBy('employee_id');
        $this->assertSame([3, 2, 1], [$lines[$kamal->id]->full_days, $lines[$kamal->id]->half_days, $lines[$kamal->id]->leave_days]);
        $this->assertSame('8000.00', (string) $lines[$kamal->id]->gross_pay);
        // Half of 1,555.55 is 777.775, rounded half up to the cent.
        $this->assertSame('777.78', (string) $lines[$nimal->id]->gross_pay);

        $this->assertSame('8777.780000', $run->total('gross_pay'));
        $this->assertSame('8777.780000', $this->balance('6300'), 'Wages are charged the gross.');
        $this->assertSame('-8777.780000', $this->balance('1110'), 'The net pay left the till.');
        $this->assertSame(EmployeeAttendance::count(), EmployeeAttendance::where('payroll_run_id', $run->id)->count());
    }

    #[Test]
    public function advances_are_taken_back_in_full_and_whatever_a_salary_cannot_cover_carries_on(): void
    {
        $sunil = Employee::factory()->create(['daily_rate' => '2000.00']);

        $this->advance($sunil, '2026-08-10', '3000');
        $this->assertSame('3000.000000', $this->balance('1220'));
        $this->assertSame('-3000.000000', $this->balance('1110'));

        // An advance after the month's end belongs to the next month's salary.
        $this->advance($sunil, '2026-09-02', '500');

        $this->attend($sunil, ['2026-08-11' => 'FULL_DAY']);
        $august = $this->pay('2026-08', '2026-09-05')->lines->first();

        $this->assertSame(['2000.00', '2000.00', '0.00'], [(string) $august->gross_pay, (string) $august->advances_deducted, (string) $august->net_pay]);
        $this->assertSame('1500.000000', app(PayrollCalculator::class)->outstandingAdvances($sunil->id));
        $this->assertSame('1500.000000', $this->balance('1220'), 'The ledger agrees with what the employee still owes.');

        $this->attend($sunil, ['2026-09-01' => 'FULL_DAY']);
        $september = $this->pay('2026-09', '2026-09-30')->lines->first();

        $this->assertSame(['2000.00', '1500.00', '500.00'], [(string) $september->gross_pay, (string) $september->advances_deducted, (string) $september->net_pay]);
        $this->assertSame('0.000000', $this->balance('1220'));
    }

    #[Test]
    public function changing_a_daily_rate_never_changes_a_day_already_recorded(): void
    {
        $ruwan = Employee::factory()->create(['daily_rate' => '2000.00']);
        $this->attend($ruwan, ['2026-08-03' => 'FULL_DAY']);

        $ruwan->update(['daily_rate' => '2500.00']);
        $this->attend($ruwan, ['2026-08-04' => 'FULL_DAY']);
        // Re-saving the old day keeps its own rate.
        $this->attend($ruwan, ['2026-08-03' => 'FULL_DAY']);

        $this->assertSame('4500.00', (string) $this->pay('2026-08', '2026-09-05')->lines->first()->gross_pay);
    }

    #[Test]
    public function a_paid_day_is_frozen_but_the_rest_of_the_month_stays_open(): void
    {
        $saman = Employee::factory()->create(['daily_rate' => '2000.00']);
        $this->advance($saman, '2026-08-01', '500');
        $this->attend($saman, ['2026-08-03' => 'FULL_DAY']);
        $this->pay('2026-08', '2026-09-05');

        // The paid day cannot change…
        try {
            $this->attend($saman, ['2026-08-03' => 'HALF_DAY']);
            $this->fail('A paid day was changed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entries.0.status', $e->errors());
        }

        // …re-sending it unchanged is harmless, and a day not yet paid can still be recorded.
        $this->attend($saman, ['2026-08-03' => 'FULL_DAY', '2026-08-04' => 'FULL_DAY']);
        $this->assertNull(EmployeeAttendance::query()->whereDate('work_date', '2026-08-04')->value('payroll_run_id'));

        $this->expectExceptionObject(new RuntimeException('A paid attendance day is immutable. Reverse its payroll first.'));
        EmployeeAttendance::query()->whereDate('work_date', '2026-08-03')->firstOrFail()->update(['status' => 'LEAVE']);
    }

    /**
     * @param  list<int>|null  $employeeIds
     * @param  list<array{employee_id: int, amount: string}>|null  $deductions
     */
    private function payPeriod(string $from, string $to, string $payDate, ?array $employeeIds = null, ?array $deductions = null): PayrollRun
    {
        return app(PayPayroll::class)->handle(array_filter([
            'period_start' => $from,
            'period_end' => $to,
            'employee_ids' => $employeeIds,
            'deductions' => $deductions,
            'pay_date' => $payDate,
            'cash_bank_account_id' => $this->cash->id,
            'payment_method' => 'CASH',
        ], fn ($value): bool => $value !== null), $this->user->id);
    }

    #[Test]
    public function one_person_can_be_paid_for_a_single_day_after_work(): void
    {
        $kamal = Employee::factory()->create(['name' => 'Kamal', 'daily_rate' => '2000.00']);
        $nimal = Employee::factory()->create(['name' => 'Nimal', 'daily_rate' => '1800.00']);
        $this->attend($kamal, ['2026-09-14' => 'FULL_DAY']);
        $this->attend($nimal, ['2026-09-14' => 'FULL_DAY']);

        $run = $this->payPeriod('2026-09-14', '2026-09-14', '2026-09-14', [$kamal->id]);

        $this->assertSame('2000.000000', $run->total('gross_pay'));
        $this->assertSame(1, $run->lines()->count());
        // Nimal's day that same date is still unpaid, and is paid on its own later.
        $this->assertNull(EmployeeAttendance::query()->where('employee_id', $nimal->id)->value('payroll_run_id'));
        $this->assertSame('1800.000000', $this->payPeriod('2026-09-14', '2026-09-14', '2026-09-15')->total('gross_pay'));
    }

    #[Test]
    public function a_worked_period_is_paid_once_and_the_rest_of_the_month_later(): void
    {
        $kamal = Employee::factory()->create(['daily_rate' => '2000.00']);
        $this->attend($kamal, ['2026-08-03' => 'FULL_DAY', '2026-08-04' => 'HALF_DAY', '2026-08-20' => 'FULL_DAY']);

        $first = $this->payPeriod('2026-08-03', '2026-08-04', '2026-08-05');
        $this->assertSame('3000.000000', $first->total('gross_pay'));

        // Paying the whole month later pays only the day not yet paid.
        $this->assertSame('2000.000000', $this->pay('2026-08', '2026-09-01')->total('gross_pay'));

        // And then nothing is left to pay.
        $this->expectException(ValidationException::class);
        $this->payPeriod('2026-08-01', '2026-08-31', '2026-09-02');
    }

    #[Test]
    public function the_payer_chooses_how_much_advance_to_take_back(): void
    {
        $kamal = Employee::factory()->create(['daily_rate' => '2000.00']);
        $this->advance($kamal, '2026-09-01', '1500');
        $this->attend($kamal, ['2026-09-10' => 'FULL_DAY']);

        // At most min(owed 1,500, earned 2,000) = 1,500 may be taken back.
        try {
            $this->payPeriod('2026-09-10', '2026-09-10', '2026-09-10', null, [['employee_id' => $kamal->id, 'amount' => '1500.01']]);
            $this->fail('More advance was taken back than is owed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deductions', $e->errors());
        }

        $run = $this->payPeriod('2026-09-10', '2026-09-10', '2026-09-10', null, [['employee_id' => $kamal->id, 'amount' => '500']]);

        $this->assertSame('500.000000', $run->total('advances_deducted'));
        $this->assertSame('1500.000000', $run->total('net_pay'));
        // The rest stays owed, and the books agree.
        $this->assertSame('1000.000000', app(PayrollCalculator::class)->outstandingAdvances($kamal->id));
        $this->assertSame('1000.000000', $this->balance('1220'));
    }

    #[Test]
    public function the_pay_screen_shows_the_days_to_pay_and_marks_the_days_already_paid(): void
    {
        $kamal = Employee::factory()->create(['name' => 'Kamal', 'daily_rate' => '2000.00']);
        $this->attend($kamal, ['2026-09-14' => 'FULL_DAY', '2026-09-15' => 'HALF_DAY']);
        $first = $this->payPeriod('2026-09-14', '2026-09-14', '2026-09-14');

        $this->actingAs($this->user)
            ->get(route('payrolls.create', ['from' => '2026-09-14', 'to' => '2026-09-15', 'employee_id' => $kamal->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('payroll/payrolls/Create')
                ->has('lines', 1)
                ->where('lines.0.gross_pay', '1000.000000')
                ->where('lines.0.days.0.date', '2026-09-15')
                ->where("paidDays.{$kamal->id}.0.date", '2026-09-14')
                ->where("paidDays.{$kamal->id}.0.payroll_number", $first->payroll_number));

        $this->actingAs($this->user)->post(route('payrolls.store'), [
            'period_start' => '2026-09-14',
            'period_end' => '2026-09-15',
            'employee_ids' => [$kamal->id],
            'deductions' => [['employee_id' => $kamal->id, 'amount' => '0']],
            'pay_date' => '2026-09-15',
            'cash_bank_account_id' => $this->cash->id,
            'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0, EmployeeAttendance::query()->whereNull('payroll_run_id')->count());
    }

    #[Test]
    public function reversing_a_payroll_releases_its_days_and_the_advances_it_took_back(): void
    {
        $saman = Employee::factory()->create(['daily_rate' => '2000.00']);
        $this->advance($saman, '2026-08-01', '500');
        $this->attend($saman, ['2026-08-03' => 'FULL_DAY']);
        $run = $this->pay('2026-08', '2026-09-05');

        app(ReversePayroll::class)->handle($run, $this->user->id);

        $this->assertSame(PayrollDocumentStatus::Reversed, $run->fresh()->status);
        $this->assertSame('0.000000', $this->balance('6300'));
        $this->assertSame('500.000000', $this->balance('1220'), 'The advance is owed again.');
        $this->assertSame('500.000000', app(PayrollCalculator::class)->outstandingAdvances($saman->id));
        $this->assertNull(EmployeeAttendance::query()->firstOrFail()->payroll_run_id);

        // The month can be corrected and paid again.
        $this->attend($saman, ['2026-08-04' => 'HALF_DAY']);
        $this->assertSame('3000.000000', $this->pay('2026-08', '2026-09-06')->total('gross_pay'));
    }

    #[Test]
    public function a_month_is_paid_once_on_or_after_its_last_day_in_an_open_period(): void
    {
        $employee = Employee::factory()->create();
        $this->attend($employee, ['2026-08-03' => 'FULL_DAY']);

        foreach ([
            fn () => $this->pay('2026-08', '2026-08-30'),
            fn () => $this->pay('2026-08', '2026-11-05'),
            fn () => $this->pay('2026-07', '2026-08-05'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An invalid payroll was paid.');
            } catch (ValidationException) {
            }
        }

        $this->pay('2026-08', '2026-08-31');

        $this->expectException(ValidationException::class);
        $this->pay('2026-08', '2026-09-05');
    }

    #[Test]
    public function an_advance_a_salary_already_took_back_cannot_be_reversed(): void
    {
        $employee = Employee::factory()->create(['daily_rate' => '2000.00']);
        $this->advance($employee, '2026-08-01', '800');
        $this->advance($employee, '2026-09-10', '300');
        $this->attend($employee, ['2026-08-03' => 'FULL_DAY']);
        $this->pay('2026-08', '2026-09-05');

        $taken = $employee->salaryAdvances()->where('amount', '800')->firstOrFail();
        $open = $employee->salaryAdvances()->where('amount', '300')->firstOrFail();

        try {
            app(ReverseSalaryAdvance::class)->handle($taken, $this->user->id);
            $this->fail('An advance already taken back was reversed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        app(ReverseSalaryAdvance::class)->handle($open, $this->user->id);

        $this->assertSame(PayrollDocumentStatus::Reversed, $open->fresh()->status);
        $this->assertSame('0.000000', $this->balance('1220'));
        $this->assertSame('-2000.000000', $this->balance('1110'), '800 advanced + 1,200 net salary; the 300 came back.');
    }

    #[Test]
    public function attendance_is_never_recorded_for_the_future_or_a_new_day_of_an_inactive_employee(): void
    {
        $gone = Employee::factory()->inactive()->create();

        try {
            app(RecordAttendance::class)->handle('2026-09-21', [['employee_id' => $gone->id, 'status' => 'FULL_DAY']]);
            $this->fail('A future day was recorded.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('work_date', $e->errors());
        }

        $this->expectException(ValidationException::class);
        app(RecordAttendance::class)->handle('2026-09-01', [['employee_id' => $gone->id, 'status' => 'FULL_DAY']]);
    }

    #[Test]
    public function a_blank_status_clears_a_day_recorded_by_mistake(): void
    {
        $employee = Employee::factory()->create();
        $this->attend($employee, ['2026-09-01' => 'FULL_DAY']);

        app(RecordAttendance::class)->handle('2026-09-01', [['employee_id' => $employee->id, 'status' => null]]);

        $this->assertSame(0, EmployeeAttendance::count());
    }

    #[Test]
    public function every_payroll_screen_opens(): void
    {
        $employee = Employee::factory()->create(['daily_rate' => '2000.00']);
        $this->advance($employee, '2026-08-01', '500');
        $this->attend($employee, ['2026-08-03' => 'FULL_DAY']);
        $run = $this->pay('2026-08', '2026-09-05');
        $advance = $employee->salaryAdvances()->firstOrFail();

        $this->actingAs($this->user);

        foreach ([
            [route('employees.index'), 'payroll/employees/Index'],
            [route('employees.create'), 'payroll/employees/Create'],
            [route('employees.show', $employee), 'payroll/employees/Show'],
            [route('employees.edit', $employee), 'payroll/employees/Edit'],
            [route('attendance.index', ['date' => '2026-08-03']), 'payroll/attendance/Index'],
            [route('salary-advances.index'), 'payroll/salary-advances/Index'],
            [route('salary-advances.create'), 'payroll/salary-advances/Create'],
            [route('salary-advances.show', $advance), 'payroll/salary-advances/Show'],
            [route('payrolls.index'), 'payroll/payrolls/Index'],
            [route('payrolls.create', ['month' => '2026-09']), 'payroll/payrolls/Create'],
            [route('payrolls.show', $run), 'payroll/payrolls/Show'],
        ] as [$url, $component]) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
        }
    }

    #[Test]
    public function employees_are_numbered_in_sequence_and_keep_their_code(): void
    {
        $this->actingAs($this->user);
        $details = ['daily_rate' => '1800', 'is_active' => true];

        $this->get(route('employees.create'))->assertInertia(fn ($page) => $page->where('nextCode', 'EMP-001'));

        $this->post(route('employees.store'), ['name' => 'Kamal'] + $details)->assertSessionHasNoErrors();
        $this->post(route('employees.store'), ['name' => 'Nimal', 'code' => 'BOSS-1'] + $details)->assertSessionHasNoErrors();

        $this->assertSame(['EMP-001', 'EMP-002'], Employee::query()->orderBy('id')->pluck('code')->all());

        // Numbering continues from the highest number, and a code is never changed.
        Employee::factory()->create(['code' => 'EMP-041']);
        $nimal = Employee::query()->where('name', 'Nimal')->firstOrFail();
        $this->put(route('employees.update', $nimal), ['name' => 'Nimal P', 'code' => 'EMP-999'] + $details)->assertSessionHasNoErrors();

        $this->assertSame('EMP-002', $nimal->fresh()->code);
        $this->post(route('employees.store'), ['name' => 'Saman'] + $details);
        $this->assertSame('EMP-042', Employee::query()->where('name', 'Saman')->value('code'));
    }

    #[Test]
    public function the_attendance_register_saves_a_whole_day_from_the_screen(): void
    {
        [$a, $b] = Employee::factory()->count(2)->create();

        $this->actingAs($this->user)->put(route('attendance.update'), [
            'work_date' => '2026-09-15',
            'entries' => [
                ['employee_id' => $a->id, 'status' => 'FULL_DAY'],
                ['employee_id' => $b->id, 'status' => 'LEAVE', 'note' => 'Sick'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('attendance.index', ['date' => '2026-09-15']));

        $this->assertSame(2, EmployeeAttendance::count());
        $this->assertSame('Sick', EmployeeAttendance::where('employee_id', $b->id)->value('note'));
    }
}
