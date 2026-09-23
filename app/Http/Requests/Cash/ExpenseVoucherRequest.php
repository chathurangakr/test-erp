<?php

namespace App\Http\Requests\Cash;

use App\Enums\AccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing a DRAFT expense voucher.
 *
 * The expense accounts are constrained here to ordinary EXPENSE accounts, and again at
 * posting by AccountingPolicy. COST_OF_SALES is excluded from both: it is derived from stock
 * movements, and charging it by hand would put it out of step with the inventory ledger.
 *
 * The cash or bank account is chosen from the configured list, never typed as an account id.
 */
abstract class ExpenseVoucherRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'voucher_date' => ['required', 'date'],
            'cash_bank_account_id' => [
                'required',
                'integer',
                Rule::exists('cash_bank_accounts', 'id')->where('is_active', true),
            ],
            // Reporting only. Naming a supplier raises no payable.
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'payee_name' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.expense_account_id' => [
                'required',
                'integer',
                // Integers, not booleans — see CashBankAccountRequest::eligibleAccountRule().
                Rule::exists('accounts', 'id')
                    ->where('is_active', 1)
                    ->where('account_type', AccountType::Expense->value)
                    ->where('is_control_account', 0)
                    ->where('allow_manual_posting', 1),
            ],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => __('An expense voucher must charge at least one expense.'),
            'lines.*.expense_account_id.exists' => __('Choose an active operating expense account. Inventory, cost of sales, revenue and control accounts cannot be charged here.'),
            'lines.*.amount.gt' => __('Every expense line must be greater than zero.'),
            'cash_bank_account_id.exists' => __('Choose an active cash or bank account.'),
        ];
    }
}
