<?php

namespace App\Http\Requests\Advances;

use App\Http\Requests\Concerns\ValidatesPaymentMethod;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A customer or supplier advance. The party field follows the route: customer advances name a
 * `customer_id`, supplier advances a `supplier_id`. Whether the account fits the payment method
 * is checked again by the action, under lock.
 */
class StoreAdvanceRequest extends FormRequest
{
    use ValidatesPaymentMethod;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $party = $this->routeIs('supplier-advances.*') ? 'supplier' : 'customer';

        return [
            "{$party}_id" => ['required', 'integer', Rule::exists("{$party}s", 'id')->where('is_active', 1)],
            'advance_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', new Money, 'gt:0'],
            'cash_bank_account_id' => ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ] + $this->paymentMethodRules();
    }
}
