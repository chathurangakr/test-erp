<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Employee codes
    |--------------------------------------------------------------------------
    |
    | Employees are numbered automatically when added: the prefix, a dash and
    | the next number, zero-padded (EMP-001, EMP-002 … EMP-1000). A code is
    | never reused or changed. See App\Actions\Payroll\CreateEmployee.
    |
    */

    'employee_code_prefix' => env('PAYROLL_EMPLOYEE_CODE_PREFIX', 'EMP'),

    'employee_code_digits' => 3,

];
