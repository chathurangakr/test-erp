<?php

namespace App\Http\Requests\Cash;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a DRAFT fund transfer.
 *
 * `different` catches the same-account case early so the form can say so; PostFundTransfer
 * checks it again, because a rule that only exists in a form request does not apply to an
 * API or a seeder.
 */
class StoreFundTransferRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $configured = fn () => Rule::exists('cash_bank_accounts', 'id')->where('is_active', true);

        return [
            'transfer_date' => ['required', 'date'],
            'from_cash_bank_account_id' => ['required', 'integer', $configured()],
            'to_cash_bank_account_id' => ['required', 'integer', 'different:from_cash_bank_account_id', $configured()],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_cash_bank_account_id.different' => __('A transfer must be between two different cash or bank accounts.'),
            'from_cash_bank_account_id.exists' => __('Choose an active cash or bank account to transfer from.'),
            'to_cash_bank_account_id.exists' => __('Choose an active cash or bank account to transfer to.'),
        ];
    }
}
