<?php

namespace App\Http\Requests\Production;

use App\Enums\ProductionType;
use App\Models\ProductionRecipe;
use App\Support\DecimalMath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for creating and editing a draft husk chipping order.
 *
 * Unlike dehusking, the output quantities ARE submitted: chipping yields genuinely vary, so
 * the operator weighs the 7C, 9C and 10C actually produced and enters those figures. There
 * is no husk-to-KG conversion anywhere for them to be derived from.
 *
 * What is still NOT accepted is any cost figure. Costs come from the ledger at posting time
 * and are shared using the recipe's configured percentages.
 *
 * Waste is optional and informational — it creates no inventory. Its measurement basis is
 * unconfirmed (docs/BUSINESS_RULES.md §19 TBD-1), so the unit is chosen from the UOM master
 * rather than assumed.
 */
abstract class HuskChippingRequest extends FormRequest
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

            'outputs' => ['required', 'array', 'min:1'],
            'outputs.*.product_id' => ['required', 'integer', Rule::in($this->recipeOutputIds()->all())],
            'outputs.*.quantity' => ['required', 'numeric', 'min:0'],

            'waste_quantity' => ['nullable', 'numeric', 'min:0'],
            'waste_uom_id' => [
                'nullable',
                'integer',
                'required_with:waste_quantity',
                Rule::exists('units_of_measure', 'id')->where('is_active', true),
            ],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lot_id.required' => __('Select the raw husk lot being processed, so the output lots remain traceable.'),
            'quantity.gt' => __('The quantity to process must be greater than zero.'),
            'outputs.*.quantity.min' => __('An output quantity cannot be negative.'),
            'outputs.*.quantity.required' => __('Enter the measured weight, or 0 if this grade was not produced.'),
            'waste_uom_id.required_with' => __('Choose the unit the waste was measured in.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{product_id?: mixed, quantity?: mixed}> $outputs */
            $outputs = $this->input('outputs', []);

            if (! is_array($outputs) || $outputs === []) {
                return;
            }

            $submitted = collect($outputs)->pluck('product_id')->map(fn ($id): int => (int) $id);

            // Every grade must be accounted for, even a grade this batch did not produce —
            // an explicit 0 is a measurement, a missing line is an omission.
            $missing = $this->recipeOutputIds()->diff($submitted);

            if ($missing->isNotEmpty()) {
                $validator->errors()->add('outputs', __('Enter a measured weight for every output grade.'));
            }

            if ($submitted->duplicates()->isNotEmpty()) {
                $validator->errors()->add('outputs', __('Each output grade may appear only once.'));
            }

            $anyPositive = collect($outputs)->contains(
                fn (array $output): bool => is_numeric($output['quantity'] ?? null)
                    && DecimalMath::isPositive(DecimalMath::of((string) $output['quantity'])),
            );

            if (! $anyPositive) {
                $validator->errors()->add(
                    'outputs',
                    __('At least one output must have a measured quantity greater than zero.'),
                );
            }
        });
    }

    /**
     * The products the husk chipping recipe is allowed to produce.
     *
     * @return Collection<int, int>
     */
    protected function recipeOutputIds(): Collection
    {
        $recipe = ProductionRecipe::forType(ProductionType::HuskChipping);

        return $recipe === null
            ? collect()
            : $recipe->outputs->pluck('product_id')->map(fn ($id): int => (int) $id)->values();
    }
}
