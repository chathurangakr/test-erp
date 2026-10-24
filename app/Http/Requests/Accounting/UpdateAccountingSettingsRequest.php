<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountingSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Nullable: clearing it switches operational accounting back off, which is only
            // possible while no operational journal exists.
            'operational_accounting_start_date' => ['nullable', 'date'],
            'grni_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)],
            'wages_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', 1)],
            'employee_advances_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', 1)],
            'stock_loss_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', 1)],
            'loans_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', 1)],
            'loan_interest_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', 1)],
            'opening_balance_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', 1)],
        ];
    }
}
