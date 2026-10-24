<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\RecordAttendanceRequest;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The daily attendance register: pick a day, mark everyone full day, half day or leave.
 */
class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $today = BusinessClock::today();
        $date = $request->date('date') !== null
            ? CarbonImmutable::parse($request->date('date')->toDateString())
            : $today;

        $recorded = EmployeeAttendance::query()
            ->with('payrollRun:id,payroll_number')
            ->whereDate('work_date', $date->toDateString())
            ->get()
            ->keyBy('employee_id');

        // Everyone active, plus anyone deactivated since who was recorded that day.
        $employees = Employee::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $recorded->keys()))
            ->orderBy('name')
            ->get();

        return Inertia::render('payroll/attendance/Index', [
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'statuses' => AttendanceStatus::options(),
            'rows' => $employees->map(function (Employee $employee) use ($recorded): array {
                $day = $recorded->get($employee->id);

                return [
                    'employee_id' => $employee->id,
                    'code' => $employee->code,
                    'name' => $employee->name,
                    'is_active' => $employee->is_active,
                    'daily_rate' => (string) ($day?->daily_rate ?? $employee->daily_rate),
                    'status' => $day?->status->value,
                    'note' => $day?->note,
                    // Paid days are read-only; everyone else's day stays open.
                    'paid_payroll' => $day?->payrollRun === null ? null : [
                        'id' => $day->payrollRun->id,
                        'payroll_number' => $day->payrollRun->payroll_number,
                    ],
                ];
            })->values(),
        ]);
    }

    public function update(RecordAttendanceRequest $request, RecordAttendance $action): RedirectResponse
    {
        $action->handle($request->validated('work_date'), $request->validated('entries'), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attendance saved.')]);

        return to_route('attendance.index', ['date' => $request->validated('work_date')]);
    }
}
