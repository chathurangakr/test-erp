<?php

namespace App\Http\Requests\Loans;

use App\Enums\LoanFunding;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a loan: a new one paid into a cash/bank account, or one already running entered with
 * what is still owed.
 */
class StoreLoanRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $received = $this->input('funding') === LoanFunding::Received->value;

        return [
            ...UpdateLoanRequest::scheduleRules(),
            'start_date' => ['required', 'date'],
            'funding' => ['required', Rule::enum(LoanFunding::class)],
            'amount' => ['required', 'numeric', 'gt:0', new Money],
            'cash_bank_account_id' => $received
                ? ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', 1)]
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['cash_bank_account_id.required' => __('Choose the cash or bank account the loan was paid into.')];
    }
}
