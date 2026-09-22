<?php

namespace App\Http\Requests\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules for creating and editing an account.
 *
 * Note what is NOT here: any balance. An account's balance is derived from posted journal
 * lines and can never be typed.
 */
abstract class AccountRequest extends FormRequest
{
    abstract protected function accountId(): ?int;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('accounts', 'code')->ignore($this->accountId()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'normal_balance' => ['required', Rule::enum(NormalBalance::class)],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('accounts', 'id')->whereNot('id', $this->accountId()),
            ],
            'is_control_account' => ['boolean'],
            'allow_manual_posting' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => __('An account with this code already exists.'),
            'parent_id.exists' => __('The selected parent account does not exist, or is this account itself.'),
        ];
    }
}
