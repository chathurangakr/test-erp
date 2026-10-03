<?php

namespace App\Http\Requests\Sales\Concerns;

use App\Models\ProductionRecipe;
use App\Support\DecimalMath;
use Illuminate\Validation\Validator;

/**
 * "Raw husks used" on a delivery line.
 *
 * A product made by a recipe that defers its raw material to delivery (husk chipping) must
 * state how much of that raw material the delivered goods used — a positive amount in the raw
 * material's own unit. Every other product must state none. Which products need it comes from
 * the recipe, never from a product code. PostDelivery enforces the same rule again at posting.
 */
trait ValidatesBackflush
{
    protected function validateBackflush(Validator $validator): void
    {
        $inputs = ProductionRecipe::backflushInputsByOutput();

        foreach ((array) $this->input('lines', []) as $index => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $raw = $line['backflush_quantity'] ?? null;
            $given = $raw !== null && $raw !== '';
            $field = "lines.{$index}.backflush_quantity";
            $input = $inputs->get($productId);

            if ($input === null) {
                if ($given) {
                    $validator->errors()->add($field, __('This product uses no raw material at delivery, so no raw material quantity can be entered.'));
                }

                continue;
            }

            if (! $given || ! is_numeric($raw) || ! DecimalMath::isPositive(DecimalMath::of((string) $raw))) {
                $validator->errors()->add($field, __('Enter how many :input were used to make the goods delivered.', [
                    'input' => $input->name,
                ]));

                continue;
            }

            $places = (int) ($input->primaryUom?->decimal_places ?? 0);

            if (DecimalMath::decimalPlaces(DecimalMath::of((string) $raw)) > $places) {
                $validator->errors()->add($field, __(':uom allows at most :places decimal place(s).', [
                    'uom' => $input->primaryUom?->code,
                    'places' => $places,
                ]));
            }
        }
    }
}
