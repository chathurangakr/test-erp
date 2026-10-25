<?php

namespace App\Services\Payroll;

use App\Enums\PayrollDocumentStatus;
use App\Models\EmployeeAttendance;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payslips for a paid payroll — read-only, built from the payroll's own lines and the attendance
 * days it paid, never recalculated. Each slip shows the days (with the rate each was recorded at),
 * what was earned, the advance taken back, what was handed over, and the advance still owed right
 * after this payment. See docs/PAYROLL.md.
 */
class Payslips
{
    /**
     * One slip per person paid (or only the one named), by name.
     *
     * @return list<array<string, mixed>>
     */
    public function for(PayrollRun $run, ?int $employeeId = null): array
    {
        $run->loadMissing(['cashBankAccount', 'lines.employee']);
        $days = EmployeeAttendance::query()
            ->where('payroll_run_id', $run->id)
            ->when($employeeId, fn (Builder $query, int $id) => $query->where('employee_id', $id))
            ->orderBy('work_date')
            ->get()
            ->groupBy('employee_id');

        return $run->lines
            ->when($employeeId, fn ($lines) => $lines->where('employee_id', $employeeId))
            ->sortBy(fn (PayrollLine $line): string => (string) $line->employee?->name)
            ->map(fn (PayrollLine $line): array => [
                'employee_id' => $line->employee_id,
                'code' => $line->employee?->code,
                'name' => $line->employee?->name,
                'nic' => $line->employee?->nic,
                'payroll_number' => $run->payroll_number,
                'period_start' => $run->period_start->toDateString(),
                'period_end' => $run->period_end->toDateString(),
                'pay_date' => $run->pay_date->toDateString(),
                'paid_by' => $run->payment_method->label().($run->cashBankAccount ? ' · '.$run->cashBankAccount->display_name : ''),
                'reference' => $run->reference,
                'reversed' => $run->status !== PayrollDocumentStatus::Posted,
                'days' => ($days[$line->employee_id] ?? collect())->map(fn (EmployeeAttendance $day): array => [
                    'date' => $day->work_date->toDateString(),
                    'status' => $day->status->label(),
                    'rate' => (string) $day->daily_rate,
                    'pay' => DecimalMath::money($day->pay()),
                ])->values()->all(),
                'full_days' => $line->full_days,
                'half_days' => $line->half_days,
                'leave_days' => $line->leave_days,
                'gross_pay' => (string) $line->gross_pay,
                'advances_deducted' => (string) $line->advances_deducted,
                'net_pay' => (string) $line->net_pay,
                'advance_still_owed' => $this->owedAfter($run, $line->employee_id),
            ])
            ->values()
            ->all();
    }

    /**
     * Advances the employee still owed right after this payroll: standing advances dated up to the
     * pay date, less everything standing payrolls took back up to and including this one.
     */
    private function owedAfter(PayrollRun $run, int $employeeId): string
    {
        $advanced = (string) SalaryAdvance::query()
            ->where('employee_id', $employeeId)
            ->where('status', PayrollDocumentStatus::Posted)
            ->whereOnOrBefore('advance_date', $run->pay_date->toDateString())
            ->sum('amount');

        $takenBack = (string) PayrollLine::query()
            ->where('employee_id', $employeeId)
            ->whereHas('payrollRun', fn (Builder $query) => $query
                ->where('status', PayrollDocumentStatus::Posted)
                ->where(fn (Builder $when) => $when
                    ->whereBeforeDay('pay_date', $run->pay_date->toDateString())
                    ->orWhere(fn (Builder $same) => $same->whereOnDay('pay_date', $run->pay_date->toDateString())->where('id', '<=', $run->id))))
            ->sum('advances_deducted');

        $owed = DecimalMath::money(DecimalMath::sub(DecimalMath::of($advanced), DecimalMath::of($takenBack)));

        return DecimalMath::isNegative($owed) ? DecimalMath::money('0') : $owed;
    }
}
