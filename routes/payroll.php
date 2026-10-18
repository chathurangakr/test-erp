<?php

use App\Enums\Permission;
use App\Http\Controllers\Payroll\AttendanceController;
use App\Http\Controllers\Payroll\EmployeeController;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Controllers\Payroll\SalaryAdvanceController;
use Illuminate\Support\Facades\Route;

/*
 * Payroll — employees, daily attendance, salary advances and monthly salaries.
 *
 * Employees are a master (no destroy route). Advances and salaries are paid in one step and
 * corrected by reversal, never edited. See docs/PAYROLL.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('payroll/employees', EmployeeController::class)
        ->names('employees')
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::PayrollView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::PayrollEmployeesManage->middleware());

    Route::get('payroll/attendance', [AttendanceController::class, 'index'])
        ->name('attendance.index')
        ->can(Permission::PayrollView);
    Route::put('payroll/attendance', [AttendanceController::class, 'update'])
        ->name('attendance.update')
        ->can(Permission::PayrollAttendanceRecord);

    Route::resource('payroll/salary-advances', SalaryAdvanceController::class)
        ->parameters(['salary-advances' => 'salaryAdvance'])
        ->names('salary-advances')
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], Permission::PayrollView->middleware())
        ->middlewareFor(['create', 'store'], Permission::PayrollAdvancePay->middleware());
    Route::patch('payroll/salary-advances/{salaryAdvance}/reverse', [SalaryAdvanceController::class, 'reverse'])
        ->name('salary-advances.reverse')
        ->can(Permission::PayrollAdvanceReverse);

    Route::resource('payroll/salaries', PayrollController::class)
        ->parameters(['salaries' => 'payroll'])
        ->names('payrolls')
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], Permission::PayrollView->middleware())
        ->middlewareFor(['create', 'store'], Permission::PayrollSalaryPay->middleware());
    Route::patch('payroll/salaries/{payroll}/reverse', [PayrollController::class, 'reverse'])
        ->name('payrolls.reverse')
        ->can(Permission::PayrollSalaryReverse);
});
