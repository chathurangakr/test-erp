<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Writing off damaged stock. The value is never typed — the ledger decides it.
 */
class StoreStockDamageRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'damage_date' => ['required', 'date'],
            // Bags lost by a customer are written off on the customer bags screen instead.
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', 1)->where('holds_customer_items', 0)],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'lines.*.lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.exists' => __('Stock cannot be written off from an inactive warehouse.'),
            'lines.required' => __('Add at least one damaged product.'),
            'lines.*.quantity.gt' => __('The damaged quantity must be greater than zero.'),
        ];
    }
}
