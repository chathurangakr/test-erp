<?php

namespace App\Http\Requests\Payroll;

use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying a salary advance.
 */
class StoreSalaryAdvanceRequest extends FormRequest
{
    use PayrollPaymentRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('is_active', 1)],
            'advance_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', new Money, 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
            ...$this->paymentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => __('Advances can only be paid to an active employee.'),
            'amount.gt' => __('The advance must be greater than zero.'),
        ];
    }
}
