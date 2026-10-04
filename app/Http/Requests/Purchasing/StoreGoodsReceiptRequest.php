<?php

namespace App\Http\Requests\Purchasing;

use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A goods receipt may be raised against an approved purchase order or directly.
 *
 * Note what is NOT accepted here: material_cost, allocated_landed_cost and
 * final_unit_cost are calculated at posting time and can never be typed in by a user.
 */
class StoreGoodsReceiptRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')],
            'receipt_date' => ['required', 'date'],
            'supplier_delivery_reference' => ['nullable', 'string', 'max:100'],

            'transport_cost' => ['required', 'numeric', new Money, 'min:0'],
            'loading_cost' => ['required', 'numeric', new Money, 'min:0'],
            'other_cost' => ['required', 'numeric', new Money, 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer', Rule::exists('purchase_order_lines', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.secondary_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', new Money, 'min:0'],
            'lines.*.supplier_lot_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('A goods receipt must have at least one line.'),
            'lines.*.product_id.exists' => __('An inactive product cannot receive new stock.'),
            'lines.*.quantity.gt' => __('The received quantity must be greater than zero.'),
        ];
    }
}
