<?php

namespace App\Http\Requests\Production;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing a draft dehusking order.
 *
 * Note what is NOT accepted: output products, output quantities and every cost figure.
 * Outputs are derived from the recipe, and costs come from the ledger at posting time — a
 * user can neither type nor override them.
 */
class StoreDehuskingRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'production_date' => ['required', 'date'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'lot_id' => ['required', 'integer', Rule::exists('inventory_lots', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lot_id.required' => __('Select the coconut lot being processed, so the output lots remain traceable.'),
            'quantity.gt' => __('The quantity to process must be greater than zero.'),
        ];
    }
}
