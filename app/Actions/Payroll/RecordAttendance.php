<?php

namespace App\Actions\Payroll;

use App\Enums\AttendanceStatus;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves one day's attendance register: for each employee, full day, half day, leave — or
 * nothing, which clears a day recorded by mistake.
 *
 * A new day takes the employee's current daily rate; a day already recorded keeps the rate it
 * was recorded at. A day already PAID cannot change — reverse its payroll first — while other
 * people's days, and this person's unpaid days, stay open. No day can be recorded in the future.
 */
class RecordAttendance
{
    /**
     * @param  list<array{employee_id: int, status: string|null, note?: string|null}>  $entries
     */
    public function handle(string $date, array $entries, ?int $userId = null): void
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        if (BusinessClock::isFuture($day)) {
            throw ValidationException::withMessages([
                'work_date' => __('Attendance cannot be recorded for a future date.'),
            ]);
        }

        DB::transaction(function () use ($day, $entries, $userId): void {
            $employees = Employee::query()
                ->whereIn('id', array_column($entries, 'employee_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($entries as $index => $entry) {
                $employee = $employees->get($entry['employee_id']);
                $status = $entry['status'] === null ? null : AttendanceStatus::from($entry['status']);

                $existing = EmployeeAttendance::query()
                    ->with('payrollRun:id,payroll_number')
                    ->where('employee_id', $entry['employee_id'])
                    ->whereOnDay('work_date', $day->toDateString())
                    ->first();

                // A paid day stays as it was paid; the register re-sending it unchanged is fine.
                if ($existing?->payroll_run_id !== null) {
                    if ($status === $existing->status && ($entry['note'] ?? null) === $existing->note) {
                        continue;
                    }

                    throw ValidationException::withMessages([
                        "entries.{$index}.status" => __(':employee was already paid for this day (:reference). Reverse that payroll before changing it.', [
                            'employee' => $employee?->name ?? '',
                            'reference' => $existing->payrollRun?->payroll_number ?? '',
                        ]),
                    ]);
                }

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
