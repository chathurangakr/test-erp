<?php

namespace App\Http\Requests\Production\Concerns;

use App\Enums\ProductionType;
use App\Models\ProductionRecipe;
use Illuminate\Validation\Rule;

/**
 * The input lot rule for a production form, decided by the recipe's input product.
 *
 * A lot-tracked input must name one of its own lots; a POOLED input (tracks_lots = false)
 * has none, so a lot is refused rather than silently ignored. See docs/INVENTORY_RULES.md §15.
 */
trait ValidatesInputLot
{
    /**
     * @return list<mixed>
     */
    protected function inputLotRules(ProductionType $type): array
    {
        $input = ProductionRecipe::forType($type)?->inputProduct;

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
            'product' => ProductionRecipe::forType($type)?->inputProduct?->code ?? '',
        ]);
    }
}
