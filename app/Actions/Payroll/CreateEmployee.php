<?php

namespace App\Actions\Payroll;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Adds an employee with the next code in sequence — EMP-001, EMP-002, … — so nobody has to
 * choose one. The number is the highest existing one plus one, read under lock so two people
 * adding employees at the same moment cannot get the same code (the unique index is the last
 * line of defence). A code is never reused or changed afterwards.
 */
class CreateEmployee
{
    /**
     * @param  array<string, mixed>  $attributes  everything but the code
     */
    public function handle(array $attributes): Employee
    {
        return DB::transaction(function () use ($attributes): Employee {
            return Employee::create(['code' => $this->nextCode()] + $attributes);
        });
    }

    /**
     * The code the next employee will get. Call inside a transaction to hold it.
     */
    public function nextCode(): string
    {
        $prefix = mb_strtoupper((string) config('payroll.employee_code_prefix')).'-';

        $highest = Employee::query()
            ->where('code', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('code')
            ->map(fn (string $code): int => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), (int) config('payroll.employee_code_digits'), '0', STR_PAD_LEFT);
    }
}
