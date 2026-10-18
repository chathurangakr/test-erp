<?php

namespace App\Http\Requests\Payroll;

use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or editing an employee. The daily rate applies to days recorded from now on; days
 * already recorded keep their own rate.
 */
class EmployeeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('employees', 'code')->ignore($employee)],
            'name' => ['required', 'string', 'max:150'],
            'nic' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'daily_rate' => ['required', 'numeric', new Money, 'gt:0'],
            'joined_on' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => __('An employee with this code already exists.'),
            'daily_rate.gt' => __('The daily rate must be greater than zero.'),
        ];
    }
}
