<?php

namespace App\Actions\Payroll;

use App\Enums\PaymentMethod;
use App\Enums\PayrollDocumentStatus;
use App\Models\CashBankAccount;
use App\Models\Employee;
use App\Models\FiscalPeriod;
use App\Models\SalaryAdvance;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays an employee a salary advance, in one step:
 *
 *   Dr employee advances (the employee owes it back)
 *       Cr the cash / bank account
 *
 * The next salary paid for a month ending on or after the advance takes it back in full; what
 * that salary cannot cover carries on. Every rule is checked before anything is written, and
 * the advance and its journal are written in one transaction.
 */
class RecordSalaryAdvance
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly GeneratePayrollReference $references,
        private readonly WritePayrollJournal $journal,
    ) {}

    /**
     * @param  array{employee_id: int, advance_date: string, amount: string, cash_bank_account_id: int, payment_method: string, reference?: string|null, note?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): SalaryAdvance
    {
        return DB::transaction(function () use ($data, $userId): SalaryAdvance {
            $employee = Employee::query()->whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();

            if (! $employee->is_active) {
                throw ValidationException::withMessages([
                    'employee_id' => __('Advances can only be paid to an active employee.'),
                ]);
            }

            $amount = DecimalMath::money(DecimalMath::of($data['amount']));

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('The advance must be greater than zero.')]);
            }

            $date = CarbonImmutable::parse($data['advance_date']);
            $method = PaymentMethod::from($data['payment_method']);
            $configuration = CashBankAccount::query()->with('account')->findOrFail($data['cash_bank_account_id']);
            $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('a salary advance'));
            $this->policy->assertMethodFitsAccount($method, $configuration, 'cash_bank_account_id');
            $advancesAccount = $this->policy->employeeAdvancesAccount();
            $period = $this->period($date);

            $advance = SalaryAdvance::create([
                'advance_number' => $this->references->forSalaryAdvance($date),
                'employee_id' => $employee->id,
                'advance_date' => $date->toDateString(),
                'amount' => $amount,
                'fiscal_period_id' => $period->id,
                'cash_bank_account_id' => $configuration->id,
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => PayrollDocumentStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->journal->handle(
                $advance,
                $date,
                $period,
                RecordText::of('Salary advance :reference to :employee', [
                    'reference' => $advance->advance_number,
                    'employee' => "{$employee->code} {$employee->name}",
                ]),
                [
                    [$advancesAccount->id, $amount, '0', $employee->name],
                    [$configuration->account_id, '0', $amount, $data['reference'] ?? null],
                ],
                $userId,
            );

            // Written during creation, so this is not a post-posting change.
            SalaryAdvance::withoutEvents(fn () => $advance->forceFill(['journal_entry_id' => $journal->id])->save());

            return $advance->refresh();
        });
    }

    private function period(CarbonImmutable $date): FiscalPeriod
    {
        $period = FiscalPeriod::openFor($date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'advance_date' => __('There is no open fiscal period covering :date. Open the period first.', [
                    'date' => $date->toDateString(),
                ]),
            ]);
        }

        return $period;
    }
}
