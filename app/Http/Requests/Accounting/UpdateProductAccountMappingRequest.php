<?php

namespace App\Http\Requests\Accounting;

use App\Enums\AccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mapping one product to its accounts.
 *
 * Each account must be the right CLASS of account. A mapping that pointed inventory at a
 * revenue account would post silently and wrongly for as long as nobody looked.
 */
class UpdateProductAccountMappingRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'inventory_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')
                    ->where('is_active', true)
                    ->where('account_type', AccountType::Asset->value),
            ],
            'cogs_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')
                    ->where('is_active', true)
                    ->where('account_type', AccountType::CostOfSales->value),
            ],
            'revenue_account_id' => [
                'nullable', 'integer',
                Rule::exists('accounts', 'id')
                    ->where('is_active', true)
                    ->where('account_type', AccountType::Revenue->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inventory_account_id.exists' => __('The inventory account must be an active ASSET account.'),
            'cogs_account_id.exists' => __('The cost of sales account must be an active COST OF SALES account.'),
            'revenue_account_id.exists' => __('The revenue account must be an active REVENUE account.'),
        ];
    }
}
