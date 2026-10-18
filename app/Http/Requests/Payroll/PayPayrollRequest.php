<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Paying a month's salaries. The amounts are never sent: they are worked out on the server at
 * the moment of paying.
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
            'month' => ['required', 'date_format:Y-m'],
            'pay_date' => ['required', 'date'],
            ...$this->paymentRules(),
        ];
    }
}
