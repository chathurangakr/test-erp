<?php

namespace App\Http\Requests\Sales;

use App\Enums\PricingBasis;
use App\Http\Requests\Sales\Concerns\ValidatesBackflush;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A delivery may be raised against an approved sales order or directly.
 *
 * Note what is NOT accepted: sales_value, inventory_unit_cost and cost_of_goods_sold are
 * all calculated at posting time. The inventory cost in particular comes from the ledger,
 * never from the request — a salesperson must never be able to state what stock cost.
 */
class UpdateDeliveryRequest extends FormRequest
{
    use ValidatesBackflush;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'sales_order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')],
            'delivery_date' => ['required', 'date'],
            'customer_reference' => ['nullable', 'string', 'max:100'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'lines.*.sales_order_line_id' => ['nullable', 'integer', Rule::exists('sales_order_lines', 'id')],
            'lines.*.lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.secondary_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.pricing_basis' => ['required', Rule::enum(PricingBasis::class)],
            'lines.*.unit_price' => ['required', 'numeric', new Money, 'min:0'],
            // Raw material used, for products that backflush it — see ValidatesBackflush.
            'lines.*.backflush_quantity' => ['nullable', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('A delivery must have at least one line.'),
            'lines.*.quantity.gt' => __('The delivered quantity must be greater than zero.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateBackflush($validator));
    }
}
