<?php

namespace App\Http\Requests\Production;

use App\Enums\ProductionType;
use App\Models\Product;
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
 * A chipping run takes NO raw husk at production — the husk is backflushed when the outputs
 * are delivered — so there is no input, no lot and no husk quantity here. A submitted
 * `lot_id` or `quantity` is refused, not ignored: a client still sending them believes husk
 * is being consumed, and must learn otherwise.
 *
 * The operator records what the batch produced as flexible rows: any product the recipe
 * allows (7C / 9C / 10C / Cocopeat, by weight or by volume — each unit its own stock item),
 * each with a positive measured quantity in that product's own unit. A grade not produced is
 * simply left out; zero rows are refused. There is NO conversion from husks to KG or litres.
 *
 * No cost figure is ever accepted. Waste is optional and informational — it creates no
 * inventory, and its unit is chosen from the UOM master rather than assumed.
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
            'lot_id' => ['prohibited'],
            'quantity' => ['prohibited'],

            'outputs' => ['required', 'array', 'min:1'],
            'outputs.*.product_id' => ['required', 'integer', 'distinct', Rule::in($this->recipeOutputIds()->all())],
            'outputs.*.quantity' => ['required', 'numeric', 'gt:0'],

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
            'lot_id.prohibited' => __('Husk chipping takes no raw husk at production — it is deducted when the goods are delivered — so no lot can be chosen.'),
            'quantity.prohibited' => __('Husk chipping takes no raw husk at production — it is deducted when the goods are delivered — so no husk quantity can be entered.'),
            'outputs.required' => __('Add at least one output with its measured quantity.'),
            'outputs.min' => __('Add at least one output with its measured quantity.'),
            'outputs.*.product_id.distinct' => __('Each output product may appear only once.'),
            'outputs.*.product_id.in' => __('That product is not an output of husk chipping.'),
            'outputs.*.quantity.required' => __('Enter the measured quantity.'),
            'outputs.*.quantity.gt' => __('Every output row needs a quantity greater than zero. Remove rows that produced nothing.'),
            'waste_uom_id.required_with' => __('Choose the unit the waste was measured in.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $outputs = $this->input('outputs', []);

            if (! is_array($outputs)) {
                return;
            }

            $products = Product::query()
                ->with('primaryUom')
                ->whereIn('id', collect($outputs)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id))
                ->get()
                ->keyBy('id');

            // Each quantity is in its product's own unit, at that unit's precision.
            foreach ($outputs as $index => $output) {
                $product = $products->get((int) ($output['product_id'] ?? 0));
                $quantity = $output['quantity'] ?? null;

                if ($product === null || ! is_numeric($quantity)) {
                    continue;
                }

                $places = (int) $product->primaryUom->decimal_places;

                if (DecimalMath::decimalPlaces(DecimalMath::of((string) $quantity)) > $places) {
                    $validator->errors()->add("outputs.{$index}.quantity", __(':uom allows at most :places decimal place(s).', [
                        'uom' => $product->primaryUom->code,
                        'places' => $places,
                    ]));
                }
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
