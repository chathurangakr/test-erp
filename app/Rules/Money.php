<?php

namespace App\Rules;

use App\Support\DecimalMath;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An amount of money as people type it: rupees and cents.
 *
 * At most `accounting.money_decimals` (2) significant decimal places. Trailing zeros are
 * fine — "100", "100.50" and "100.500000" (how the API transmits money) all pass — but
 * "100.505" does not: a fraction of a cent can be neither paid nor billed. Pair with
 * 'numeric'; non-numeric input is left to that rule.
 */
class Money implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $places = (int) config('accounting.money_decimals', 2);
        $amount = DecimalMath::of((string) $value);

        if (DecimalMath::compare(DecimalMath::round($amount, $places), $amount) !== 0) {
            $fail(__('Enter an amount with at most :places decimal places (rupees and cents).', ['places' => $places]));
        }
    }
}
