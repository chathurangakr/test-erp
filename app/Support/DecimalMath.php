<?php

namespace App\Support;

/**
 * Decimal-safe arithmetic for inventory quantities and costs.
 *
 * Every figure is handled as a numeric STRING via BCMath. PHP floats are never used for
 * quantities or costs: 0.1 + 0.2 !== 0.3 in binary floating point, and an inventory
 * valuation that drifts by fractions of a cent per movement becomes unreconcilable.
 * See CLAUDE.md rule 6.
 */
final class DecimalMath
{
    /** Working scale for intermediate results — wider than any stored column. */
    public const SCALE = 12;

    /** Scale of the stored quantity columns, decimal(20,3). */
    public const QUANTITY_SCALE = 3;

    /** Scale of the stored cost columns, decimal(20,6). */
    public const COST_SCALE = 6;

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcmul($a, $b, $scale);
    }

    /**
     * Division that returns '0' rather than throwing when the divisor is zero.
     *
     * Zero divisors are expected: a product with no stock has no weighted-average cost.
     */
    public static function div(string $a, string $b, int $scale = self::SCALE): string
    {
        if (self::isZero($b)) {
            return self::zero($scale);
        }

        return bcdiv($a, $b, $scale);
    }

    /**
     * @return int -1 when $a < $b, 0 when equal, 1 when $a > $b
     */
    public static function compare(string $a, string $b, int $scale = self::SCALE): int
    {
        return bccomp($a, $b, $scale);
    }

    public static function isZero(string $value, int $scale = self::SCALE): bool
    {
        return bccomp($value, '0', $scale) === 0;
    }

    public static function isNegative(string $value, int $scale = self::SCALE): bool
    {
        return bccomp($value, '0', $scale) === -1;
    }

    public static function isPositive(string $value, int $scale = self::SCALE): bool
    {
        return bccomp($value, '0', $scale) === 1;
    }

    public static function lessThan(string $a, string $b, int $scale = self::SCALE): bool
    {
        return bccomp($a, $b, $scale) === -1;
    }

    /**
     * Round half-up to the given scale. BCMath truncates, so the half is added explicitly.
     */
    public static function round(string $value, int $scale): string
    {
        $half = self::isNegative($value, self::SCALE) ? '-0.5' : '0.5';
        $shift = bcpow('10', (string) $scale, 0);

        $shifted = bcmul($value, $shift, self::SCALE);
        $rounded = bcadd($shifted, $half, 0);

        return bcdiv($rounded, $shift, $scale);
    }

    public static function quantity(string $value): string
    {
        return self::round($value, self::QUANTITY_SCALE);
    }

    public static function cost(string $value): string
    {
        return self::round($value, self::COST_SCALE);
    }

    public static function zero(int $scale = self::SCALE): string
    {
        return number_format(0, $scale, '.', '');
    }

    /**
     * Normalise any acceptable numeric input to a plain decimal string.
     *
     * Integers and numeric strings pass through unchanged. Floats are accepted only at this
     * boundary (a caller may hold one) and are converted with full precision immediately;
     * they never take part in arithmetic.
     */
    public static function of(int|string|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, self::SCALE, '.', ''), '0'), '.') ?: '0';
        }

        $trimmed = trim($value);

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * How many decimal places the given value actually uses.
     */
    public static function decimalPlaces(string $value): int
    {
        $value = rtrim($value, '0');
        $position = strpos($value, '.');

        if ($position === false) {
            return 0;
        }

        return strlen(substr($value, $position + 1));
    }
}
