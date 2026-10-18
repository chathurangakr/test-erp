<?php

namespace App\Actions\Payroll;

use App\Enums\PaymentMethod;
use App\Enums\PayrollDocumentStatus;
use App\Models\CashBankAccount;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\FiscalPeriod;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Payroll\PayrollCalculator;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays a month's salaries, in one step:
 *
 *   Dr wages                       — everything earned (gross)
 *       Cr employee advances       — advances taken back
 *       Cr the cash / bank account — what was handed over (net)
 *
 * The figures are worked out again under lock at the moment of paying (PayrollCalculator), so a
 * preview that went stale can never be what gets paid. One payroll per month: a second is
 * refused while the first stands. Paying freezes the month's attendance days; reversing the
 * payroll releases them. Everything is written in one transaction with its journal.
 */
class PayPayroll
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly PayrollCalculator $calculator,
        private readonly GeneratePayrollReference $references,
        private readonly WritePayrollJournal $journal,
    ) {}

    /**
     * @param  array{month: string, pay_date: string, cash_bank_account_id: int, payment_method: string, reference?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): PayrollRun
    {
        [$start, $end] = $this->calculator->monthBounds($data['month']);
        $payDate = CarbonImmutable::parse($data['pay_date'])->startOfDay();

        if ($payDate->lessThan($end)) {
            throw ValidationException::withMessages([
                'pay_date' => __('Salaries for a month can be paid from its last day (:date) onwards.', ['date' => $end->toDateString()]),
            ]);
        }

        return DB::transaction(function () use ($data, $start, $end, $payDate, $userId): PayrollRun {
            // Lock everyone with a day in the month, so attendance, advances and a second
            // payroll for the same people wait for this one.
            $employeeIds = EmployeeAttendance::query()
                ->whereDate('work_date', '>=', $start->toDateString())
                ->whereDate('work_date', '<=', $end->toDateString())
                ->distinct()
                ->pluck('employee_id');
            Employee::query()->whereIn('id', $employeeIds)->lockForUpdate()->get();

            $existing = $this->calculator->paidPayrollCovering($start);

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'month' => __('Salaries for this month are already paid (:reference).', ['reference' => $existing->payroll_number]),
                ]);
            }

            $lines = $this->calculator->lines($start, $end);
            $gross = DecimalMath::zero();
            $deducted = DecimalMath::zero();
            $net = DecimalMath::zero();

            foreach ($lines as $line) {
                $gross = DecimalMath::add($gross, $line['gross_pay']);
                $deducted = DecimalMath::add($deducted, $line['advances_deducted']);
                $net = DecimalMath::add($net, $line['net_pay']);
            }

            if (! DecimalMath::isPositive($gross)) {
                throw ValidationException::withMessages([
                    'month' => __('There is nothing to pay for this month: no full or half days are recorded.'),
                ]);
            }

            $method = PaymentMethod::from($data['payment_method']);
            $configuration = CashBankAccount::query()->with('account')->findOrFail($data['cash_bank_account_id']);
            $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('a payroll'));
            $this->policy->assertMethodFitsAccount($method, $configuration, 'cash_bank_account_id');
            $wages = $this->policy->wagesAccount();
            $advances = $this->policy->employeeAdvancesAccount();
            $period = FiscalPeriod::openFor($payDate) ?? throw ValidationException::withMessages([
                'pay_date' => __('There is no open fiscal period covering :date. Open the period first.', ['date' => $payDate->toDateString()]),
            ]);

            $run = PayrollRun::create([
                'payroll_number' => $this->references->forPayroll($payDate),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'pay_date' => $payDate->toDateString(),
                'fiscal_period_id' => $period->id,
                'cash_bank_account_id' => $configuration->id,
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'status' => PayrollDocumentStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            foreach ($lines as $line) {
                PayrollLine::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $line['employee']->id,
                    'full_days' => $line['full_days'],
                    'half_days' => $line['half_days'],
                    'leave_days' => $line['leave_days'],
                    'gross_pay' => $line['gross_pay'],
                    'advances_deducted' => $line['advances_deducted'],
                    'net_pay' => $line['net_pay'],
                ]);

                EmployeeAttendance::query()->whereIn('id', $line['attendance_ids'])->update(['payroll_run_id' => $run->id]);
            }

            $journal = $this->journal->handle(
                $run,
                $payDate,
                $period,
                RecordText::of('Salaries for :month paid (:reference)', [
                    'month' => $start->format('F Y'),
                    'reference' => $run->payroll_number,
                ]),
                [
                    [$wages->id, DecimalMath::money($gross), '0', null],
                    [$advances->id, '0', DecimalMath::money($deducted), null],
                    [$configuration->account_id, '0', DecimalMath::money($net), $data['reference'] ?? null],
                ],
                $userId,
            );

            // Written during creation, so this is not a post-posting change.
            PayrollRun::withoutEvents(fn () => $run->forceFill(['journal_entry_id' => $journal->id])->save());

            return $run->refresh();
        });
    }
}
