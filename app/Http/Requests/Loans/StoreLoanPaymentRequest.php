<?php

namespace App\Http\Requests\Loans;

use App\Enums\PaymentMethod;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An installment actually paid: the amount and the interest part from the bank's slip. What
 * reduces the loan is worked out on the server.
 */
class StoreLoanPaymentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', new Money],
            'interest_amount' => ['nullable', 'numeric', 'min:0', new Money],
            'cash_bank_account_id' => ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', 1)],
            'payment_method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])],
            'reference' => ['nullable', 'string', 'max:100'],
            'principal_amount' => ['prohibited'],
        ];
    }
}
