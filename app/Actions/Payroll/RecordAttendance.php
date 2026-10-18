<?php

namespace App\Actions\Payroll;

use App\Enums\AttendanceStatus;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Services\Payroll\PayrollCalculator;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves one day's attendance register: for each employee, full day, half day, leave — or
 * nothing, which clears a day recorded by mistake.
 *
 * A new day takes the employee's current daily rate; a day already recorded keeps the rate it
 * was recorded at. Nothing may change in a month whose salaries are already paid — reverse that
 * payroll first — and no day can be recorded in the future.
 */
class RecordAttendance
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    /**
     * @param  list<array{employee_id: int, status: string|null, note?: string|null}>  $entries
     */
    public function handle(string $date, array $entries, ?int $userId = null): void
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        if ($day->greaterThan(BusinessClock::today())) {
            throw ValidationException::withMessages([
                'work_date' => __('Attendance cannot be recorded for a future date.'),
            ]);
        }

        DB::transaction(function () use ($day, $entries, $userId): void {
            $paid = $this->calculator->paidPayrollCovering($day);

            if ($paid !== null) {
                throw ValidationException::withMessages([
                    'work_date' => __('Salaries for this month are already paid (:reference). Reverse that payroll before changing its attendance.', [
                        'reference' => $paid->payroll_number,
                    ]),
                ]);
            }

            $employees = Employee::query()
                ->whereIn('id', array_column($entries, 'employee_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($entries as $index => $entry) {
                $employee = $employees->get($entry['employee_id']);
                $status = $entry['status'] === null ? null : AttendanceStatus::from($entry['status']);

                $existing = EmployeeAttendance::query()
                    ->where('employee_id', $entry['employee_id'])
                    ->whereDate('work_date', $day->toDateString())
                    ->first();

                if ($status === null) {
                    $existing?->delete();

                    continue;
                }

                if ($existing !== null) {
                    $existing->update(['status' => $status, 'note' => $entry['note'] ?? null, 'recorded_by' => $userId]);

                    continue;
                }

                if ($employee === null || ! $employee->is_active) {
                    throw ValidationException::withMessages([
                        "entries.{$index}.employee_id" => __('Attendance can only be recorded for an active employee.'),
                    ]);
                }

                EmployeeAttendance::create([
                    'employee_id' => $employee->id,
                    'work_date' => $day->toDateString(),
                    'status' => $status,
                    'daily_rate' => $employee->daily_rate,
                    'note' => $entry['note'] ?? null,
                    'recorded_by' => $userId,
                ]);
            }
        });
    }
}
