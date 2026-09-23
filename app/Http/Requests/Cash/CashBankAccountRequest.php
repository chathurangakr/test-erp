<?php

namespace App\Http\Requests\Cash;

use App\Enums\AccountType;
use App\Enums\CashBankAccountKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Configuring which general-ledger account is a real till or bank account.
 *
 * The account rules are stated twice on purpose: here, so the form can show a helpful error,
 * and again in AccountingPolicy when money actually moves, so an API or a seeder is held to
 * the same rule. This request is the convenience; the policy is the guarantee.
 */
abstract class CashBankAccountRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge($this->accountRules(), [
            'account_kind' => ['required', new Enum(CashBankAccountKind::class)],
            'display_name' => ['required', 'string', 'max:150'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'branch_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    abstract protected function accountRules(): array;

    /**
     * An account that may be configured: active, an asset, not a control account, and not a
     * group header.
     */
    protected function eligibleAccountRule(): object
    {
        // Integers, not booleans: Rule::exists() stringifies its where values, and
        // (string) false is the empty string, which matches no row at all.
        return Rule::exists('accounts', 'id')
            ->where('is_active', 1)
            ->where('account_type', AccountType::Asset->value)
            ->where('is_control_account', 0)
            ->where('allow_manual_posting', 1);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.exists' => __('Choose an active asset account that is not a control account or a group header.'),
            'account_id.unique' => __('That general-ledger account is already configured as a cash or bank account.'),
        ];
    }
}
