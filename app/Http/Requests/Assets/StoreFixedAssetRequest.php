<?php

namespace App\Http\Requests\Assets;

use App\Enums\AssetFunding;
use App\Enums\PaymentMethod;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding an asset: paid in full, bought on a loan (with an optional down payment), or already
 * owned. A loan's lender and reminder schedule come with it.
 */
class StoreFixedAssetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $funding = $this->input('funding');
        $loan = $funding === AssetFunding::Loan->value;
        $paysNow = $funding === AssetFunding::CashBank->value || ($loan && (float) $this->input('down_payment', 0) > 0);

        return [
            ...UpdateFixedAssetRequest::detailRules(),
            'purchase_date' => ['required', 'date'],
            'purchase_price' => ['required', 'numeric', 'gt:0', new Money],
            'funding' => ['required', Rule::enum(AssetFunding::class)],
            'down_payment' => $loan ? ['nullable', 'numeric', 'min:0', new Money] : ['prohibited'],
            'cash_bank_account_id' => $paysNow ? ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', 1)] : ['nullable'],
            'payment_method' => $paysNow ? ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])] : ['nullable'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lender' => $loan ? ['required', 'string', 'max:150'] : ['prohibited'],
            'has_interest' => ['boolean'],
            'installment_amount' => $loan ? ['nullable', 'numeric', 'gt:0', new Money] : ['prohibited'],
            'due_day' => $loan ? ['nullable', 'integer', 'between:1,31'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cash_bank_account_id.required' => __('Choose the cash or bank account it was paid from.'),
            'lender.required' => __('Enter who lent the money for this asset.'),
            'funding.enum' => __('Choose how it was paid for.'),
        ];
    }
}
