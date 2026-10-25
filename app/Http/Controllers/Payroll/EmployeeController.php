<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\CreateEmployee;
use App\Enums\PayrollDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\EmployeeRequest;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PayrollLine;
use App\Models\SalaryAdvance;
use App\Services\Payroll\PayrollCalculator;
use App\Support\BusinessClock;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employees — who the factory pays by the day, and at what rate.
 *
 * A master: no destroy route, deactivated instead. What an employee earned or owes is shown
 * from attendance, advances and paid salaries; nothing of it is stored on the employee.
 */
class EmployeeController extends Controller
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status', 'active');

        $list = ListQuery::from($request)->sortable([
            'code' => 'code',
            'name' => 'name',
            'phone' => 'phone',
            'daily_rate' => 'daily_rate',
            'status' => 'is_active',
        ], 'name', 'asc');

        $employees = $list->paginate(Employee::query()
            ->search((string) $request->string('search'))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false)))
            ->through(fn (Employee $employee): array => $this->summarise($employee) + [
                'advances_owed' => $this->owed($employee),
            ]);

        return Inertia::render('payroll/employees/Index', [
            'employees' => $employees,
            'filters' => ['search' => (string) $request->string('search'), 'status' => $status] + $list->filters(),
        ]);
    }

    public function create(CreateEmployee $createEmployee): Response
    {
        return Inertia::render('payroll/employees/Create', [
            // A preview only: the code is taken when the employee is saved.
            'nextCode' => $createEmployee->nextCode(),
        ]);
    }

    public function store(EmployeeRequest $request, CreateEmployee $createEmployee): RedirectResponse
    {
        $employee = $createEmployee->handle($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Employee added.')]);

        return to_route('employees.show', $employee);
    }

    public function show(Employee $employee): Response
    {
        $monthStart = BusinessClock::today()->startOfMonth();

        $days = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $monthStart->subMonth()->toDateString())
            ->orderByDesc('work_date')
            ->get();

        return Inertia::render('payroll/employees/Show', [
            'employee' => $this->summarise($employee) + [
                'nic' => $employee->nic,
                'joined_on' => $employee->joined_on?->toDateString(),
                'notes' => $employee->notes,
                'advances_owed' => $this->owed($employee),
            ],
            'attendance' => $days->map(fn (EmployeeAttendance $day): array => [
                'work_date' => $day->work_date->toDateString(),
                'status' => $day->status->value,
                'status_label' => $day->status->label(),
                'daily_rate' => (string) $day->daily_rate,
                'pay' => $day->pay(),
                'note' => $day->note,
                'paid' => $day->payroll_run_id !== null,
            ])->values(),
            'advances' => SalaryAdvance::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('advance_date')->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (SalaryAdvance $advance): array => [
                    'id' => $advance->id,
                    'advance_number' => $advance->advance_number,
                    'advance_date' => $advance->advance_date->toDateString(),
                    'amount' => (string) $advance->amount,
                    'status' => $advance->status->value,
                    'status_label' => $advance->status->label(),
                ])->values(),
            'salaries' => PayrollLine::query()
                ->with('payrollRun')
                ->where('employee_id', $employee->id)
                ->orderByDesc('id')
                ->limit(24)
                ->get()
                ->map(fn (PayrollLine $line): array => [
                    'payroll_id' => $line->payroll_run_id,
                    'payroll_number' => $line->payrollRun->payroll_number,
                    'month' => $line->payrollRun->period_start->format('Y-m'),
                    'full_days' => $line->full_days,
                    'half_days' => $line->half_days,
                    'leave_days' => $line->leave_days,
                    'gross_pay' => (string) $line->gross_pay,
                    'advances_deducted' => (string) $line->advances_deducted,
                    'net_pay' => (string) $line->net_pay,
                    'reversed' => $line->payrollRun->status === PayrollDocumentStatus::Reversed,
                ])->values(),
        ]);
    }

    public function edit(Employee $employee): Response
    {
        return Inertia::render('payroll/employees/Edit', [
            'employee' => $this->summarise($employee) + [
                'nic' => $employee->nic,
                'joined_on' => $employee->joined_on?->toDateString(),
                'notes' => $employee->notes,
            ],
        ]);
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Employee updated.')]);

        return to_route('employees.show', $employee);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'code' => $employee->code,
            'name' => $employee->name,
            'phone' => $employee->phone,
            'daily_rate' => (string) $employee->daily_rate,
            'is_active' => $employee->is_active,
        ];
    }

    private function owed(Employee $employee): string
    {
        $owed = $this->calculator->outstandingAdvances($employee->id);

        return str_starts_with($owed, '-') ? '0.00' : $owed;
    }
}
