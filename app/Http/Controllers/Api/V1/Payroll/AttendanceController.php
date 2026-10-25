<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Actions\Payroll\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\RecordAttendanceRequest;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The daily attendance register over the API — one day, every employee.
 *
 * Saving is naturally safe to repeat: it sets each employee's day to the status sent, so a
 * retried request changes nothing. A blank status clears a day recorded by mistake.
 */
class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);

        return response()->json(['data' => $this->register(
            $request->filled('date') ? CarbonImmutable::parse($request->string('date')->toString()) : BusinessClock::today(),
        )]);
    }

    public function update(RecordAttendanceRequest $request, RecordAttendance $action): JsonResponse
    {
        $action->handle($request->validated('work_date'), $request->validated('entries'), $request->user()->id);

        return response()->json(['data' => $this->register(CarbonImmutable::parse($request->validated('work_date')))]);
    }

    /**
     * Everyone active (plus anyone deactivated since who was recorded that day), with that day's
     * status and the rate it carries — the same rows the website's register shows.
     *
     * @return array<string, mixed>
     */
    private function register(CarbonImmutable $date): array
    {
        $recorded = EmployeeAttendance::query()
            ->with('payrollRun:id,payroll_number')
            ->whereOnDay('work_date', $date->toDateString())
            ->get()
            ->keyBy('employee_id');

        $employees = Employee::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $recorded->keys()))
            ->orderBy('name')
            ->get();

        // Days are paid one by one now (1.13.0): each row says whether it was paid. The top-level
        // field is kept for older apps and is set only when every recorded day was paid.
        $allPaid = $recorded->isNotEmpty() && $recorded->every(fn (EmployeeAttendance $day): bool => $day->payroll_run_id !== null);
        $paid = $allPaid ? $recorded->first()->payrollRun : null;

        return [
            'date' => $date->toDateString(),
            'paid_payroll' => $paid === null ? null : ['id' => $paid->id, 'payroll_number' => $paid->payroll_number],
            'statuses' => AttendanceStatus::options(),
            'rows' => $employees->map(function (Employee $employee) use ($recorded): array {
                $day = $recorded->get($employee->id);

                return [
                    'employee' => ['id' => $employee->id, 'code' => $employee->code, 'name' => $employee->name],
                    'is_active' => $employee->is_active,
                    'daily_rate' => DecimalMath::cost((string) ($day?->daily_rate ?? $employee->daily_rate)),
                    'status' => $day === null ? null : ['value' => $day->status->value, 'label' => $day->status->label()],
                    'pay' => $day === null ? null : DecimalMath::cost($day->pay()),
                    'note' => $day?->note,
                    // Paid days are read-only; everyone else's day stays open.
                    'paid_payroll' => $day?->payrollRun === null ? null : ['id' => $day->payrollRun->id, 'payroll_number' => $day->payrollRun->payroll_number],
                ];
            })->values()->all(),
        ];
    }
}
