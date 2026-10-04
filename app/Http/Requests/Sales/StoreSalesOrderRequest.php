<?php

namespace App\Http\Requests\Sales;

use App\Enums\PricingBasis;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesOrderRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'customer_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.secondary_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.pricing_basis' => ['required', Rule::enum(PricingBasis::class)],
            'lines.*.unit_price' => ['required', 'numeric', new Money, 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('A sales order must have at least one line.'),
            'lines.*.product_id.exists' => __('An inactive product cannot be sold.'),
            'lines.*.quantity.gt' => __('The ordered quantity must be greater than zero.'),
        ];
    }
}
