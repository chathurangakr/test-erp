<?php

namespace App\Http\Requests\Payroll;

use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding or editing an employee. The code is assigned automatically and never edited (see
 * CreateEmployee). The daily rate applies to days recorded from now on; days already recorded
 * keep their own rate.
 */
class EmployeeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
            'daily_rate.gt' => __('The daily rate must be greater than zero.'),
        ];
    }
}
