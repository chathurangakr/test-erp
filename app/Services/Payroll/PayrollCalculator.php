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
 * Works out a month's salaries — read-only. Paying them is App\Actions\Payroll\PayPayroll.
 *
 *   gross    = Σ each day's pay (full day = its rate, half day = half of it, leave = nothing)
 *   deducted = min(advances still owed for advances dated up to the month's end, gross)
 *   net      = gross − deducted
 *
 * Whatever advance a salary cannot cover stays owed and is taken from the next month. Every
 * figure is derived from attendance, advances and paid payrolls; nothing is stored until a
 * payroll is paid. See docs/PAYROLL.md.
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
     * The paid (not reversed) payroll for the month containing $date, if any.
     */
    public function paidPayrollCovering(CarbonImmutable|string $date): ?PayrollRun
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        return PayrollRun::query()
            ->where('status', PayrollDocumentStatus::Posted)
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
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
            ->when($upTo, fn ($query) => $query->whereDate('advance_date', '<=', $upTo->toDateString()))
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
     * One row per employee with unpaid attendance in the month.
     *
     * @return Collection<int, array{employee: Employee, full_days: int, half_days: int, leave_days: int, gross_pay: string, advances_owed: string, advances_deducted: string, net_pay: string, attendance_ids: list<int>}>
     */
    public function lines(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $days = EmployeeAttendance::query()
            ->whereNull('payroll_run_id')
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->orderBy('work_date')
            ->get()
            ->groupBy('employee_id');

        $employees = Employee::query()->whereIn('id', $days->keys())->get()->keyBy('id');

        return $days
            ->map(function (Collection $rows, int $employeeId) use ($employees, $end): array {
                $gross = DecimalMath::zero();

                foreach ($rows as $row) {
                    $gross = DecimalMath::add($gross, $row->pay());
                }

                $gross = DecimalMath::money($gross);
                $owed = $this->outstandingAdvances($employeeId, $end);
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
                ];
            })
            ->sortBy(fn (array $line): string => $line['employee']->name)
            ->values();
    }
}
