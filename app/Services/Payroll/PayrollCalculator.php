<?php

namespace App\Services\Payroll;

use App\Enums\AttendanceStatus;
use App\Enums\PayrollDocumentStatus;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Works out salaries for any period — a single day, a few days or a month — read-only. Paying
 * them is App\Actions\Payroll\PayPayroll.
 *
 *   gross             = Σ each UNPAID day's pay (full day = its rate, half = half, leave = nothing)
 *   most_deductible   = min(advances still owed as of the pay date, gross)
 *   deducted          = what the payer chooses, 0 … most_deductible (suggested: the most)
 *   net               = gross − deducted
 *
 * A day is paid once: a day a standing payroll already paid is never counted again. Whatever
 * advance is not taken back stays owed. Every figure is derived from attendance, advances and
 * paid payrolls; nothing is stored until a payroll is paid. See docs/PAYROLL.md.
 */
class PayrollCalculator
{
    /**
     * The first and last day of a "YYYY-MM" month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function monthBounds(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => __('Choose a month.')]);
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [$start->startOfMonth(), $start->endOfMonth()->startOfDay()];
    }

    /**
     * A pay period: from and to, inclusive, in the business calendar.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodBounds(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['period_end' => __('The period must end on or after its first day.')]);
        }

        return [$start, $end];
    }

    /**
     * The paid (not reversed) payroll covering $date, if any. Since payrolls may cover any
     * period for some employees only, this says only that SOME pay covered that date.
     */
    public function paidPayrollCovering(CarbonImmutable|string $date): ?PayrollRun
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        return PayrollRun::query()
            ->where('status', PayrollDocumentStatus::Posted)
            ->whereOnOrBefore('period_start', $date)
            ->whereOnOrAfter('period_end', $date)
            ->first();
    }

    /**
     * What one employee still owes in advances.
     *
     * Paid advances (dated up to $upTo when given) less everything paid payrolls took back.
     * Reversed advances and reversed payrolls count for nothing.
     */
    public function outstandingAdvances(int $employeeId, ?CarbonImmutable $upTo = null): string
    {
        $advanced = (string) SalaryAdvance::query()
            ->where('employee_id', $employeeId)
            ->where('status', PayrollDocumentStatus::Posted)
            ->when($upTo, fn ($query) => $query->whereOnOrBefore('advance_date', $upTo->toDateString()))
            ->sum('amount');

        return DecimalMath::money(DecimalMath::sub(DecimalMath::of($advanced), $this->deducted($employeeId)));
    }

    /**
     * Everything paid payrolls have taken back from one employee.
     */
    public function deducted(int $employeeId): string
    {
        return DecimalMath::of((string) PayrollLine::query()
            ->where('employee_id', $employeeId)
            ->whereHas('payrollRun', fn ($query) => $query->where('status', PayrollDocumentStatus::Posted))
            ->sum('advances_deducted'));
    }

    /**
     * One row per employee with UNPAID attendance in the period — optionally only the named
     * employees. Advances count when dated up to $advancesUpTo (the pay date), else the period's
     * end. `advances_deducted` is the suggestion: the most that may be taken back.
     *
     * @param  list<int>|null  $employeeIds
     * @return Collection<int, array{employee: Employee, full_days: int, half_days: int, leave_days: int, gross_pay: string, advances_owed: string, advances_deducted: string, net_pay: string, attendance_ids: list<int>, days: list<array{date: string, status: string, pay: string}>}>
     */
    public function lines(CarbonImmutable $start, CarbonImmutable $end, ?array $employeeIds = null, ?CarbonImmutable $advancesUpTo = null): Collection
    {
        $days = EmployeeAttendance::query()
            ->whereNull('payroll_run_id')
            ->whereOnOrAfter('work_date', $start->toDateString())
            ->whereOnOrBefore('work_date', $end->toDateString())
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->orderBy('work_date')
            ->get()
            ->groupBy('employee_id');

        $employees = Employee::query()->whereIn('id', $days->keys())->get()->keyBy('id');
        $advancesUpTo ??= $end;

        return $days
            ->map(function (Collection $rows, int $employeeId) use ($employees, $advancesUpTo): array {
                $gross = DecimalMath::zero();

                foreach ($rows as $row) {
                    $gross = DecimalMath::add($gross, $row->pay());
                }

                $gross = DecimalMath::money($gross);
                $owed = $this->outstandingAdvances($employeeId, $advancesUpTo);
                $deducted = DecimalMath::isPositive($owed)
                    ? (DecimalMath::lessThan($owed, $gross) ? $owed : $gross)
                    : DecimalMath::money('0');

                return [
                    'employee' => $employees[$employeeId],
                    'full_days' => $rows->where('status', AttendanceStatus::FullDay)->count(),
                    'half_days' => $rows->where('status', AttendanceStatus::HalfDay)->count(),
                    'leave_days' => $rows->where('status', AttendanceStatus::Leave)->count(),
                    'gross_pay' => $gross,
                    'advances_owed' => DecimalMath::isPositive($owed) ? $owed : DecimalMath::money('0'),
                    'advances_deducted' => DecimalMath::money($deducted),
                    'net_pay' => DecimalMath::money(DecimalMath::sub($gross, $deducted)),
                    'attendance_ids' => $rows->pluck('id')->all(),
                    'days' => $rows->map(fn (EmployeeAttendance $row): array => [
                        'date' => $row->work_date->toDateString(),
                        'status' => $row->status->value,
                        'pay' => DecimalMath::money($row->pay()),
                    ])->values()->all(),
                ];
            })
            ->sortBy(fn (array $line): string => $line['employee']->name)
            ->values();
    }

    /**
     * Days in the period a standing payroll already paid, by employee — shown so nobody tries to
     * pay them twice.
     *
     * @param  list<int>|null  $employeeIds
     * @return Collection<int, list<array{date: string, status: string, payroll_id: int, payroll_number: string}>>
     */
    public function paidDays(CarbonImmutable $start, CarbonImmutable $end, ?array $employeeIds = null): Collection
    {
        return EmployeeAttendance::query()
            ->with('payrollRun:id,payroll_number')
            ->whereNotNull('payroll_run_id')
            ->whereOnOrAfter('work_date', $start->toDateString())
            ->whereOnOrBefore('work_date', $end->toDateString())
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->orderBy('work_date')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows): array => $rows->map(fn (EmployeeAttendance $row): array => [
                'date' => $row->work_date->toDateString(),
                'status' => $row->status->value,
                'payroll_id' => $row->payroll_run_id,
                'payroll_number' => $row->payrollRun?->payroll_number,
            ])->values()->all());
    }
}
