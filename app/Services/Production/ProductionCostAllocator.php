<?php

namespace App\Services\Production;

use App\Models\Product;
use App\Support\DecimalMath;
use Illuminate\Validation\ValidationException;

/**
 * Shares a production run's consumed inventory value across its saleable outputs.
 *
 * Used by every production type, so the value-conservation guarantee exists in exactly one
 * place. Two rules define it:
 *
 *  1. **Only outputs with a positive quantity participate.** An output that a batch did not
 *     actually produce must not carry inventory value — there is no stock for the value to
 *     sit on. The remaining outputs' configured percentages are renormalised so they again
 *     total 100%.
 *
 *     Configured 7C 30 / 9C 30 / 10C 40, and a batch that produced no 9C:
 *
 *         7C  = 30 / (30 + 40) = 42.857143%
 *         10C = 40 / (30 + 40) = 57.142857%
 *         9C  = nothing
 *
 *  2. **The last participating output absorbs the rounding remainder**, so the allocated
 *     costs sum EXACTLY to the consumed value whatever the percentages are. Production
 *     therefore neither creates nor destroys inventory value.
 *
 * See docs/BUSINESS_RULES.md §19.
 */
class ProductionCostAllocator
{
    /**
     * @param  list<array{product: Product, quantity: string, percent: string}>  $outputs
     *                                                                                     Quantities are already decided by the caller — derived from a ratio for a
     *                                                                                     fixed-ratio type, or entered by the operator otherwise.
     * @return list<array{
     *     product: Product,
     *     quantity: string,
     *     configured_percent: string,
     *     effective_percent: string,
     *     allocated_cost: string,
     *     unit_cost: string
     * }>
     */
    public function allocate(array $outputs, string $consumedValue): array
    {
        if ($outputs === []) {
            throw ValidationException::withMessages([
                'outputs' => __('A production run must have at least one output.'),
            ]);
        }

        // Which outputs this particular batch actually produced.
        $participating = [];
        $percentTotal = DecimalMath::zero();

        foreach ($outputs as $index => $output) {
            $quantity = DecimalMath::of($output['quantity']);

            if (DecimalMath::isNegative($quantity)) {
                throw ValidationException::withMessages([
                    "outputs.{$index}.quantity" => __('An output quantity cannot be negative.'),
                ]);
            }

            if (DecimalMath::isPositive($quantity)) {
                $participating[] = $index;
                $percentTotal = DecimalMath::add($percentTotal, DecimalMath::of($output['percent']));
            }
        }

        if ($participating === []) {
            throw ValidationException::withMessages([
                'outputs' => __('At least one output must have a quantity greater than zero.'),
            ]);
        }

        // Every producing output configured at 0% would leave the consumed value with
        // nowhere to go, so there would be no way to conserve it.
        if (DecimalMath::isZero($percentTotal)) {
            throw ValidationException::withMessages([
                'outputs' => __('The outputs produced by this batch have no configured cost allocation between them.'),
            ]);
        }

        $lastParticipating = $participating[count($participating) - 1];
        $running = DecimalMath::zero();

        $result = [];

        foreach ($outputs as $index => $output) {
            $quantity = DecimalMath::quantity(DecimalMath::of($output['quantity']));
            $configured = DecimalMath::round(DecimalMath::of($output['percent']), 6);

            if (! in_array($index, $participating, true)) {
                // Produced nothing: no share, no cost, and no lot will be created for it.
                $result[] = [
                    'product' => $output['product'],
                    'quantity' => $quantity,
                    'configured_percent' => $configured,
                    'effective_percent' => DecimalMath::round('0', 6),
                    'allocated_cost' => DecimalMath::cost('0'),
                    'unit_cost' => DecimalMath::cost('0'),
                ];

                continue;
            }

            $effective = DecimalMath::round(
                DecimalMath::mul(DecimalMath::div($configured, $percentTotal), '100'),
                6,
            );

            if ($index === $lastParticipating) {
                // Absorbs the remainder so the total is exact.
                $allocated = DecimalMath::cost(DecimalMath::sub($consumedValue, $running));
            } else {
                $allocated = DecimalMath::cost(
                    DecimalMath::mul($consumedValue, DecimalMath::div($configured, $percentTotal))
                );
                $running = DecimalMath::add($running, $allocated);
            }

            $result[] = [
                'product' => $output['product'],
                'quantity' => $quantity,
                'configured_percent' => $configured,
                'effective_percent' => $effective,
                'allocated_cost' => $allocated,
                // Quantity is positive here, so this division is always safe.
                'unit_cost' => DecimalMath::cost(DecimalMath::div($allocated, $quantity)),
            ];
        }

        return $result;
    }
}
