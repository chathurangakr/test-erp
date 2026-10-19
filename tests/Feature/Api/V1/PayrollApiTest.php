<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\CashBankAccount;
use App\Models\Employee;
use App\Models\FiscalPeriod;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use App\Models\User;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Payroll over /api/v1 — the same actions and rules as the website, with money-moving calls
 * recorded once per Idempotency-Key.
 */
class PayrollApiTest extends TestCase
{
    use RefreshDatabase;

    private CashBankAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        FiscalPeriod::factory()->create(['name' => 'August 2026', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31']);
        FiscalPeriod::factory()->create(['name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);

        AccountingSetting::query()->updateOrCreate([], [
            'wages_account_id' => Account::query()->where('code', '6300')->value('id'),
            'employee_advances_account_id' => Account::query()->where('code', '1220')->value('id'),
        ]);

        $this->cash = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        Sanctum::actingAs(User::factory()->admin()->create(), ['mobile-api']);
    }

    #[Test]
    public function an_employee_is_numbered_automatically_and_decimals_travel_as_strings(): void
    {
        $this->postJson(route('api.v1.employees.store'), ['name' => 'Kamal', 'daily_rate' => '2000'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'EMP-001')
            ->assertJsonPath('data.daily_rate', '2000.000000');

        $employee = Employee::sole();

        $this->putJson(route('api.v1.employees.update', $employee), ['name' => 'Kamal Perera', 'daily_rate' => '2100', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.code', 'EMP-001')
            ->assertJsonPath('data.advances_owed', '0.000000');
    }

    #[Test]
    public function attendance_is_recorded_for_a_day_and_repeating_the_call_changes_nothing(): void
    {
        $kamal = Employee::factory()->create(['name' => 'Kamal', 'daily_rate' => '2000.00']);
        $body = ['work_date' => '2026-08-03', 'entries' => [['employee_id' => $kamal->id, 'status' => 'HALF_DAY']]];

        $this->putJson(route('api.v1.attendance.update'), $body)->assertOk()
            ->assertJsonPath('data.rows.0.status.value', 'HALF_DAY')
            ->assertJsonPath('data.rows.0.pay', '1000.000000');
        $this->putJson(route('api.v1.attendance.update'), $body)->assertOk();

        $this->assertSame(1, $kamal->attendances()->count());
        $this->getJson(route('api.v1.attendance.index', ['date' => '2026-08-03']))
            ->assertOk()->assertJsonPath('data.rows.0.employee.code', $kamal->code);
    }

    #[Test]
    public function an_advance_sent_twice_with_one_key_is_paid_once(): void
    {
        $kamal = Employee::factory()->create(['daily_rate' => '2000.00']);
        $body = [
            'employee_id' => $kamal->id,
            'advance_date' => '2026-08-10',
            'amount' => '5000',
            'cash_bank_account_id' => $this->cash->id,
            'payment_method' => 'CASH',
        ];

        $this->postJson(route('api.v1.salary-advances.store'), $body)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $key = (string) Str::uuid();
        $first = $this->postJson(route('api.v1.salary-advances.store'), $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.amount', '5000.000000');
        $this->postJson(route('api.v1.salary-advances.store'), $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, SalaryAdvance::count());
        $this->getJson(route('api.v1.employees.show', $kamal))->assertJsonPath('data.advances_owed', '5000.000000');
    }

    #[Test]
    public function a_month_is_previewed_paid_once_and_reversed(): void
    {
        $kamal = Employee::factory()->create(['name' => 'Kamal', 'daily_rate' => '2000.00']);
        $this->putJson(route('api.v1.attendance.update'), ['work_date' => '2026-08-03', 'entries' => [['employee_id' => $kamal->id, 'status' => 'FULL_DAY']]]);
        $this->postJson(route('api.v1.salary-advances.store'), [
            'employee_id' => $kamal->id, 'advance_date' => '2026-08-04', 'amount' => '500',
            'cash_bank_account_id' => $this->cash->id, 'payment_method' => 'CASH',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->getJson(route('api.v1.payrolls.preview', ['month' => '2026-08']))
            ->assertOk()
            ->assertJsonPath('data.lines.0.gross_pay', '2000.000000')
            ->assertJsonPath('data.lines.0.advances_deducted', '500.000000')
            ->assertJsonPath('data.totals.net_pay', '1500.000000');

        $body = ['month' => '2026-08', 'pay_date' => '2026-09-01', 'cash_bank_account_id' => $this->cash->id, 'payment_method' => 'CASH'];
        $key = (string) Str::uuid();
        $paid = $this->postJson(route('api.v1.payrolls.store'), $body, ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJsonPath('data.net_pay', '1500.000000')
            ->assertJsonPath('data.lines.0.employee.name', 'Kamal');
        $this->postJson(route('api.v1.payrolls.store'), $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, PayrollRun::count());

        $payroll = PayrollRun::sole();
        $this->postJson(route('api.v1.payrolls.reverse', $payroll))->assertOk()->assertJsonPath('data.status.value', 'REVERSED');
        $this->postJson(route('api.v1.payrolls.reverse', $payroll))->assertStatus(409);
        $this->assertSame($paid->json('data.id'), $payroll->id);
    }

    #[Test]
    public function attendance_needs_its_own_permission(): void
    {
        $kamal = Employee::factory()->create();
        Sanctum::actingAs(User::factory()->withPermissions(Permission::PayrollView)->create(), ['mobile-api']);

        $this->getJson(route('api.v1.attendance.index'))->assertOk();
        $this->putJson(route('api.v1.attendance.update'), ['work_date' => '2026-08-03', 'entries' => [['employee_id' => $kamal->id, 'status' => 'FULL_DAY']]])
            ->assertForbidden();
    }
}
