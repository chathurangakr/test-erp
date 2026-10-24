<?php

namespace App\Http\Requests\Assets;

use App\Enums\PaymentMethod;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Selling an asset, or scrapping it at a price of 0 (then no money moves).
 */
class SellFixedAssetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $paid = (float) $this->input('sale_price', 0) > 0;

        return [
            'sold_date' => ['required', 'date'],
            'sale_price' => ['required', 'numeric', 'min:0', new Money],
            'buyer' => ['nullable', 'string', 'max:150'],
            'cash_bank_account_id' => $paid ? ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', 1)] : ['nullable'],
            'payment_method' => $paid ? ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])] : ['nullable'],
        ];
    }
}
