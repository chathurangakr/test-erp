<?php

namespace App\Http\Requests\Sales;

use App\Enums\CustomerBagMovementKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bags given to, returned by or lost by a customer. A loss names no store: the bags were never
 * coming back to one.
 */
class StoreCustomerBagMovementRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $lost = $this->input('kind') === CustomerBagMovementKind::Lost->value;

        return [
            'movement_date' => ['required', 'date'],
            'kind' => ['required', Rule::enum(CustomerBagMovementKind::class)],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_returnable', 1)],
            'warehouse_id' => $lost
                ? ['prohibited']
                : ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', 1)->where('holds_customer_items', 0)],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.exists' => __('Choose a returnable item, such as a bag.'),
            'warehouse_id.required' => __('Choose the store the bags leave from or come back to.'),
            'quantity.gt' => __('The number of bags must be greater than zero.'),
        ];
    }
}
