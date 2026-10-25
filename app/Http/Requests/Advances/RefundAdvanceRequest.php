<?php

namespace App\Http\Requests\Advances;

use App\Enums\PaymentMethod;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Money back from an advance. What is left of it, and whether the account fits the method, are
 * checked by the action, under lock.
 */
class RefundAdvanceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'refund_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', new Money, 'gt:0'],
            'cash_bank_account_id' => ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
