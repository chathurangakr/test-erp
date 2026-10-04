<?php

namespace App\Services\Purchasing;

use App\Support\DecimalMath;
use Illuminate\Validation\ValidationException;

/**
 * Allocates header-level landed costs (transport, loading, other) across goods receipt
 * lines in proportion to each line's material value.
 *
 *   line share = total landed cost x (line material value / total material value)
 *
 * Allocation is by VALUE, never by quantity: lines on one receipt may use different units
 * of measure, so 1,000 NOS of coconut and 400 KG of husk cannot be compared by quantity.
 *
 * Worked example — 10,000 coconut @ Rs.100, transport 20,000, loading 5,000, other 0:
 *
 *   material value        = 1,000,000
 *   total landed cost     =    25,000
 *   allocated to the line =    25,000  (only line, so all of it)
 *   final unit cost       = 1,025,000 / 10,000 = Rs.102.50
 *
 * See docs/BUSINESS_RULES.md §13.
 */
class LandedCostAllocator
{
    /**
     * @param  list<array{quantity: string, unit_price: string}>  $lines
     * @return array{
     *     material_total: string,
     *     landed_total: string,
     *     grand_total: string,
     *     lines: list<array{
     *         material_cost: string,
     *         allocated_landed_cost: string,
     *         final_unit_cost: string,
     *         total_cost: string
     *     }>
     * }
     */
    public function allocate(array $lines, string $landedTotal): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => __('A goods receipt must contain at least one line.'),
            ]);
        }

        $landedTotal = DecimalMath::cost($landedTotal);

        if (DecimalMath::isNegative($landedTotal)) {
            throw ValidationException::withMessages([
                'transport_cost' => __('Landed costs cannot be negative.'),
            ]);
        }

        $materials = [];
        $materialTotal = DecimalMath::zero();

        foreach ($lines as $line) {
            // What the supplier bills, so it is money: rounded to the cent. A quantity with
            // three decimals times a price would otherwise leave fractions of a cent that no
            // invoice could ever clear from GRNI.
            $material = DecimalMath::money(
                DecimalMath::mul(DecimalMath::of($line['quantity']), DecimalMath::of($line['unit_price']))
            );

            $materials[] = $material;
            $materialTotal = DecimalMath::add($materialTotal, $material);
        }

        $materialTotal = DecimalMath::cost($materialTotal);

        // Allocation by value is undefined when there is no value to allocate against.
        // Rather than silently spreading the cost some other way (by quantity, which is
        // meaningless across different units), the posting is refused.
        if (DecimalMath::isZero($materialTotal) && ! DecimalMath::isZero($landedTotal)) {
            throw ValidationException::withMessages([
                'lines' => __('Landed costs cannot be allocated when the total material value is zero. Enter a purchase price, or remove the landed costs.'),
            ]);
        }

        $allocated = [];
        $runningAllocation = DecimalMath::zero();
        $lastIndex = count($lines) - 1;

        foreach ($materials as $index => $material) {
            if ($index === $lastIndex) {
                // The final line absorbs the rounding remainder, so the allocations sum
                // EXACTLY to the header total. Without this the inventory value would drift
                // by fractions of a rupee on every multi-line receipt.
                $share = DecimalMath::sub($landedTotal, $runningAllocation);
            } else {
                $share = DecimalMath::cost(
                    DecimalMath::mul($landedTotal, DecimalMath::div($material, $materialTotal))
                );
                $runningAllocation = DecimalMath::add($runningAllocation, $share);
            }

            $allocated[] = DecimalMath::cost($share);
        }

        $result = [];

        foreach ($lines as $index => $line) {
            $quantity = DecimalMath::of($line['quantity']);
            $material = $materials[$index];
            $share = $allocated[$index];
            $total = DecimalMath::cost(DecimalMath::add($material, $share));

            $result[] = [
                'material_cost' => $material,
                'allocated_landed_cost' => $share,
                // Guarded division: a zero-quantity line would otherwise divide by zero.
                'final_unit_cost' => DecimalMath::cost(DecimalMath::div($total, $quantity)),
                'total_cost' => $total,
            ];
        }

        return [
            'material_total' => $materialTotal,
            'landed_total' => $landedTotal,
            'grand_total' => DecimalMath::cost(DecimalMath::add($materialTotal, $landedTotal)),
            'lines' => $result,
        ];
    }
}
