<?php

namespace App\Http\Requests\Production\Concerns;

use App\Enums\ProductionType;
use App\Models\ProductionRecipe;
use Illuminate\Validation\Rule;

/**
 * The input of a production form: which input product (a coconut size) and which lot.
 *
 * A type may have one active recipe per input product. `input_product_id` chooses it; a form
 * that names none gets the type's original recipe, exactly as before sizes existed.
 *
 * A lot-tracked input must name one of its own lots; a POOLED input (tracks_lots = false)
 * has none, so a lot is refused rather than silently ignored. See docs/INVENTORY_RULES.md §15.
 */
trait ValidatesInputLot
{
    /**
     * The recipe for the input product this request names (or the type's original).
     */
    protected function chosenRecipe(ProductionType $type): ?ProductionRecipe
    {
        $inputProductId = $this->input('input_product_id');

        return ProductionRecipe::forType($type, is_numeric($inputProductId) ? (int) $inputProductId : null);
    }

    /**
     * @return list<mixed>
     */
    protected function inputProductRules(ProductionType $type): array
    {
        return [
            'nullable',
            'integer',
            Rule::in(ProductionRecipe::activeForType($type)->pluck('input_product_id')->all()),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function inputLotRules(ProductionType $type): array
    {
        $input = $this->chosenRecipe($type)?->inputProduct;

        if ($input !== null && ! $input->tracksLots()) {
            return ['prohibited'];
        }

        return [
            'required',
            'integer',
            Rule::exists('inventory_lots', 'id')->where('product_id', $input?->id ?? 0),
        ];
    }

    protected function pooledInputMessage(ProductionType $type): string
    {
        return __(':product is kept as one pooled stock without lots, so no lot can be chosen.', [
            'product' => $this->chosenRecipe($type)?->inputProduct?->code ?? '',
        ]);
    }
}
