<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Payroll\AttendanceController;
use App\Http\Controllers\Api\V1\Payroll\EmployeeController;
use App\Http\Controllers\Api\V1\Payroll\PayrollController;
use App\Http\Controllers\Api\V1\Payroll\SalaryAdvanceController;
use Illuminate\Support\Facades\Route;

/*
 * Payroll — employees, daily attendance, salary advances and monthly salaries. Each route checks
 * the same permission as its web counterpart (routes/payroll.php), with the same route parameter
 * names. No delete routes: employees are deactivated; advances and salaries are reversed.
 *
 * Paying an advance or a month's salaries moves money in one step, so both `store` routes need
 * an Idempotency-Key (docs/API_V1.md §19). The `options` and `preview` routes have no web twin:
 * they return what the matching web form shows, under the permission to pay.
 */
Route::get('payroll/employees', [EmployeeController::class, 'index'])->name('employees.index')->can(Permission::PayrollView);
Route::post('payroll/employees', [EmployeeController::class, 'store'])->name('employees.store')->can(Permission::PayrollEmployeesManage);
Route::get('payroll/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show')->can(Permission::PayrollView);
Route::put('payroll/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update')->can(Permission::PayrollEmployeesManage);

Route::get('payroll/attendance', [AttendanceController::class, 'index'])->name('attendance.index')->can(Permission::PayrollView);
Route::put('payroll/attendance', [AttendanceController::class, 'update'])->name('attendance.update')->can(Permission::PayrollAttendanceRecord);

Route::get('payroll/salary-advances', [SalaryAdvanceController::class, 'index'])->name('salary-advances.index')->can(Permission::PayrollView);
Route::get('payroll/salary-advances/options', [SalaryAdvanceController::class, 'options'])->name('salary-advances.options')->can(Permission::PayrollAdvancePay);
Route::post('payroll/salary-advances', [SalaryAdvanceController::class, 'store'])->name('salary-advances.store')->can(Permission::PayrollAdvancePay);
Route::get('payroll/salary-advances/{salaryAdvance}', [SalaryAdvanceController::class, 'show'])->name('salary-advances.show')->can(Permission::PayrollView);
Route::post('payroll/salary-advances/{salaryAdvance}/reverse', [SalaryAdvanceController::class, 'reverse'])->name('salary-advances.reverse')->can(Permission::PayrollAdvanceReverse);

Route::get('payroll/salaries', [PayrollController::class, 'index'])->name('payrolls.index')->can(Permission::PayrollView);
Route::get('payroll/salaries/preview', [PayrollController::class, 'preview'])->name('payrolls.preview')->can(Permission::PayrollSalaryPay);
Route::post('payroll/salaries', [PayrollController::class, 'store'])->name('payrolls.store')->can(Permission::PayrollSalaryPay);
Route::get('payroll/salaries/{payroll}', [PayrollController::class, 'show'])->name('payrolls.show')->can(Permission::PayrollView);
Route::post('payroll/salaries/{payroll}/reverse', [PayrollController::class, 'reverse'])->name('payrolls.reverse')->can(Permission::PayrollSalaryReverse);
