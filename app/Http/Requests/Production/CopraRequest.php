<?php

namespace App\Http\Requests\Production;

use App\Enums\ProductionType;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeOutput;
use App\Support\DecimalMath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for creating and editing a draft copra production order.
 *
 * Copra production submits some quantities and refuses others, and the recipe decides
 * which is which:
 *
 *   MEASURED output (recipe ratio NULL)      — the quantity IS submitted and must be > 0.
 *                                              Copra weight varies with drying, so nothing
 *                                              can derive it.
 *   DERIVED output (recipe ratio present)    — the quantity is PROHIBITED. Shell count is
 *                                              one per coconut processed; the server
 *                                              calculates it, and an attempt to send a
 *                                              different figure is refused rather than
 *                                              quietly ignored.
 *   SECONDARY quantity (recipe flag)         — required and must be > 0 where the recipe
 *                                              asks for it. For copra the shell weight is
 *                                              operationally required, even though the
 *                                              inventory engine treats secondary quantities
 *                                              as informational in general.
 *                                              It is PROHIBITED anywhere else.
 *
 * No cost figure is ever accepted. Costs come from the ledger at posting time and are
 * shared using the recipe's configured percentages.
 *
 * See docs/BUSINESS_RULES.md §20.
 */
abstract class CopraRequest extends FormRequest
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
            'outputs.*.product_id' => ['required', 'integer', Rule::in($this->recipeOutputs()->keys()->all())],
            'outputs.*.quantity' => ['nullable', 'numeric'],
            'outputs.*.secondary_quantity' => ['nullable', 'numeric'],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lot_id.required' => __('Select the dehusked coconut lot being processed, so the output lots remain traceable.'),
            'quantity.gt' => __('The quantity to process must be greater than zero.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $outputs */
            $outputs = $this->input('outputs', []);

            if (! is_array($outputs) || $outputs === []) {
                return;
            }

            $configured = $this->recipeOutputs();
            $submitted = collect($outputs)->pluck('product_id')->map(fn ($id): int => (int) $id);

            if ($configured->keys()->diff($submitted)->isNotEmpty()) {
                $validator->errors()->add('outputs', __('Record every output the copra recipe produces.'));
            }

            if ($submitted->duplicates()->isNotEmpty()) {
                $validator->errors()->add('outputs', __('Each output may appear only once.'));
            }

            foreach ($outputs as $index => $output) {
                $config = $configured->get((int) ($output['product_id'] ?? 0));

                if ($config === null) {
                    continue;
                }

                $this->validateQuantity($validator, $index, $output, $config);
                $this->validateSecondaryQuantity($validator, $index, $output, $config);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function validateQuantity(Validator $validator, int $index, array $output, ProductionRecipeOutput $config): void
    {
        $quantity = $output['quantity'] ?? null;

        if ($config->derivesQuantity()) {
            // One shell per coconut processed. Accepting a figure here — even a correct
            // one — would imply the operator may set it, so it is refused outright.
            if ($quantity !== null && $quantity !== '') {
                $validator->errors()->add(
                    "outputs.{$index}.quantity",
                    __('The :product quantity follows the quantity processed and cannot be entered.', [
                        'product' => $config->product?->code ?? __('output'),
                    ]),
                );
            }

            return;
        }

        if ($quantity === null || $quantity === '' || ! is_numeric($quantity)) {
            $validator->errors()->add(
                "outputs.{$index}.quantity",
                __('Enter the measured :product quantity.', ['product' => $config->product?->code ?? __('output')]),
            );

            return;
        }

        if (! DecimalMath::isPositive(DecimalMath::of((string) $quantity))) {
            $validator->errors()->add(
                "outputs.{$index}.quantity",
                __('The measured :product quantity must be greater than zero.', [
                    'product' => $config->product?->code ?? __('output'),
                ]),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function validateSecondaryQuantity(Validator $validator, int $index, array $output, ProductionRecipeOutput $config): void
    {
        $secondary = $output['secondary_quantity'] ?? null;
        $given = $secondary !== null && $secondary !== '';

        if (! $config->requires_secondary_quantity) {
            if ($given) {
                $validator->errors()->add(
                    "outputs.{$index}.secondary_quantity",
                    __('A weight cannot be recorded for :product.', ['product' => $config->product?->code ?? __('output')]),
                );
            }

            return;
        }

        if (! $given || ! is_numeric($secondary)) {
            $validator->errors()->add(
                "outputs.{$index}.secondary_quantity",
                __('Enter the measured :product weight.', ['product' => $config->product?->code ?? __('output')]),
            );

            return;
        }

        if (! DecimalMath::isPositive(DecimalMath::of((string) $secondary))) {
            $validator->errors()->add(
                "outputs.{$index}.secondary_quantity",
                __('The measured :product weight must be greater than zero.', [
                    'product' => $config->product?->code ?? __('output'),
                ]),
            );
        }
    }

    /**
     * The copra recipe's outputs, keyed by product id.
     *
     * @return Collection<int, ProductionRecipeOutput>
     */
    protected function recipeOutputs(): Collection
    {
        $recipe = ProductionRecipe::forType(ProductionType::Copra);

        return $recipe === null
            ? collect()
            : $recipe->outputs->keyBy(fn (ProductionRecipeOutput $output): int => (int) $output->product_id);
    }
}
