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
 * Pays salaries for any period — a single day, a few days or a month — for everyone with unpaid
 * days in it, or only the employees named, in one step:
 *
 *   Dr wages                       — everything earned (gross)
 *       Cr employee advances       — advances taken back
 *       Cr the cash / bank account — what was handed over (net)
 *
 * Only UNPAID days are paid, so a day is never paid twice; paying freezes those days and
 * reversing the payroll releases them. How much advance to take back is the payer's choice per
 * employee, from nothing up to min(advances owed, gross); a request that names none takes the
 * most (the behaviour before 1.13.0). The figures are worked out again under lock at the moment
 * of paying (PayrollCalculator), so a stale preview can never be what gets paid. Everything is
 * written in one transaction with its journal. See docs/PAYROLL.md.
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
     * @param  array{month?: string|null, period_start?: string|null, period_end?: string|null, employee_ids?: list<int>|null, deductions?: list<array{employee_id: int, amount: string}>|null, pay_date: string, cash_bank_account_id: int, payment_method: string, reference?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): PayrollRun
    {
        [$start, $end] = filled($data['period_start'] ?? null)
            ? $this->calculator->periodBounds($data['period_start'], $data['period_end'] ?? $data['period_start'])
            : $this->calculator->monthBounds((string) ($data['month'] ?? ''));
        $payDate = CarbonImmutable::parse($data['pay_date'])->startOfDay();
        $only = isset($data['employee_ids']) ? array_map('intval', $data['employee_ids']) : null;
        $chosen = collect($data['deductions'] ?? [])->mapWithKeys(fn (array $row): array => [(int) $row['employee_id'] => (string) $row['amount']]);

        if ($payDate->lessThan($end)) {
            throw ValidationException::withMessages([
                'pay_date' => __('Salaries for this period can be paid from its last day (:date) onwards.', ['date' => $end->toDateString()]),
            ]);
        }

        return DB::transaction(function () use ($data, $start, $end, $payDate, $only, $chosen, $userId): PayrollRun {
            // Lock everyone with a day in the period, so attendance, advances and another
            // payroll for the same people wait for this one.
            $employeeIds = EmployeeAttendance::query()
                ->whereDate('work_date', '>=', $start->toDateString())
                ->whereDate('work_date', '<=', $end->toDateString())
                ->when($only !== null, fn ($query) => $query->whereIn('employee_id', $only))
                ->distinct()
                ->pluck('employee_id');
            Employee::query()->whereIn('id', $employeeIds)->lockForUpdate()->get();

            $lines = $this->calculator->lines($start, $end, $only, $payDate)
                ->map(fn (array $line): array => $this->applyDeduction($line, $chosen->get($line['employee']->id)));
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
                    'period_start' => __('There is nothing to pay for this period: no unpaid full or half days are recorded.'),
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
                RecordText::of('Salaries for :from to :to paid (:reference)', [
                    'from' => $start->toDateString(),
                    'to' => $end->toDateString(),
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

    /**
     * The advance taken back from one employee: the payer's figure, or — when none was given —
     * the most allowed. Never more than is owed, never more than was earned, never negative.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function applyDeduction(array $line, ?string $chosen): array
    {
        $most = $line['advances_deducted'];

        if ($chosen === null) {
            return $line;
        }

        $amount = DecimalMath::money(DecimalMath::of($chosen));

        if (DecimalMath::isNegative($amount) || DecimalMath::compare($amount, $most, 2) > 0) {
            throw ValidationException::withMessages([
                'deductions' => __('At most :most can be taken back from :employee (advances owed :owed, earned :earned).', [
                    'most' => $most,
                    'employee' => $line['employee']->name,
                    'owed' => $line['advances_owed'],
                    'earned' => $line['gross_pay'],
                ]),
            ]);
        }

        return array_merge($line, [
            'advances_deducted' => $amount,
            'net_pay' => DecimalMath::money(DecimalMath::sub($line['gross_pay'], $amount)),
        ]);
    }
}
