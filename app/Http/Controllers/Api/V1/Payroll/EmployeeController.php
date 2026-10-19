<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Actions\Payroll\CreateEmployee;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\EmployeeRequest;
use App\Http\Resources\V1\Payroll\EmployeeResource;
use App\Models\Employee;
use App\Services\Payroll\PayrollCalculator;
use App\Support\DecimalMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Employees over the API — the same validation and numbering as the website. No delete: an
 * employee is deactivated (`is_active` on update), never removed.
 */
class EmployeeController extends Controller
{
    use PaginatesResults;

    public function __construct(private readonly PayrollCalculator $calculator) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        $employees = Employee::query()
            ->search((string) $request->string('search'))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Employee $employee): Employee => $this->withOwed($employee));

        return EmployeeResource::collection($employees);
    }

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->withOwed($employee));
    }

    public function store(EmployeeRequest $request, CreateEmployee $createEmployee): JsonResponse
    {
        $employee = $createEmployee->handle($request->validated());

        return (new EmployeeResource($employee->refresh()))->response()->setStatusCode(201);
    }

    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->validated());

        return new EmployeeResource($this->withOwed($employee->refresh()));
    }

    /**
     * What the employee still owes in advances — derived, never stored.
     */
    private function withOwed(Employee $employee): Employee
    {
        $owed = $this->calculator->outstandingAdvances($employee->id);
        $employee->setAttribute('advances_owed', DecimalMath::isPositive($owed) ? $owed : '0');

        return $employee;
    }
}
