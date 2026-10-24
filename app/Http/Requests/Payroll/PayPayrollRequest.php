<?php

namespace App\Http\Requests\Payroll;

use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying salaries for a period — a month (`month`), or any days (`period_start` … `period_end`)
 * — for everyone with unpaid days, or only `employee_ids`. Earnings are never sent: they are
 * worked out on the server at the moment of paying. `deductions` is how much advance to take
 * back from each employee; one not named gives up the most allowed.
 */
class PayPayrollRequest extends FormRequest
{
    use PayrollPaymentRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['required_without:period_start', 'nullable', 'date_format:Y-m'],
            'period_start' => ['required_without:month', 'nullable', 'date'],
            'period_end' => ['required_with:period_start', 'nullable', 'date', 'after_or_equal:period_start'],
            'employee_ids' => ['nullable', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')],
            'deductions' => ['nullable', 'array'],
            'deductions.*.employee_id' => ['required', 'integer', 'distinct'],
            'deductions.*.amount' => ['required', 'numeric', 'min:0', new Money],
            'pay_date' => ['required', 'date'],
            ...$this->paymentRules(),
        ];
    }
}
