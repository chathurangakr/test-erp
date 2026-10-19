<?php

namespace App\Support;

/**
 * Figures written for people outside the screens (the weekly email): "1,890.00", "3,000".
 *
 * Works on the decimal string itself, rounded half up through DecimalMath, so no floating-point
 * error reaches a reader. Display only — never fed back into arithmetic.
 */
class DisplayNumber
{
    public static function money(string $value): string
    {
        return self::group(DecimalMath::round($value, (int) config('accounting.money_decimals', 2)));
    }

    /** A quantity: up to three decimals, trailing zeros dropped ("1,250.5", "3,000"). */
    public static function quantity(string $value): string
    {
        $rounded = DecimalMath::round($value, 3);

        return self::group(str_contains($rounded, '.') ? rtrim(rtrim($rounded, '0'), '.') : $rounded);
    }

    private static function group(string $value): string
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, null);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole === '' ? '0' : $whole);
        $isZero = trim($whole.$fraction, '0') === '';

        return ($negative && ! $isZero ? '-' : '').$grouped.($fraction === null ? '' : '.'.$fraction);
    }
}
