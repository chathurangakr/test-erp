<?php

namespace App\Http\Requests\Production;

use App\Enums\ProductionType;
use App\Http\Requests\Production\Concerns\ValidatesInputLot;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a draft dehusking order.
 *
 * Note what is NOT accepted: output products, output quantities and every cost figure.
 * Outputs are derived from the recipe, and costs come from the ledger at posting time — a
 * user can neither type nor override them.
 *
 * The labour rate per coconut IS accepted: it is the operator's statement of what the
 * dehusking cost in wages, pre-filled from the recipe's default. The labour cost itself is
 * calculated at posting (quantity x rate) and becomes the raw husk's cost.
 */
class StoreDehuskingRequest extends FormRequest
{
    use ValidatesInputLot;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'production_date' => ['required', 'date'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'input_product_id' => $this->inputProductRules(ProductionType::Dehusking),
            'lot_id' => $this->inputLotRules(ProductionType::Dehusking),
            'quantity' => ['required', 'numeric', 'gt:0'],
            'labour_rate' => ['required', 'numeric', 'min:0', new Money],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'input_product_id.in' => __('Choose which coconuts are being dehusked.'),
            'lot_id.required' => __('Select the coconut lot being processed, so the output lots remain traceable.'),
            'lot_id.prohibited' => $this->pooledInputMessage(ProductionType::Dehusking),
            'quantity.gt' => __('The quantity to process must be greater than zero.'),
            'labour_rate.required' => __('Enter the dehusking labour rate per coconut (0 if there is none).'),
            'labour_rate.min' => __('The labour rate cannot be negative.'),
        ];
    }
}
