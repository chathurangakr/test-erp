<?php

namespace App\Enums;

use App\Support\DecimalMath;

/**
 * What an employee did on a day, and what that day pays.
 *
 * Leave is unpaid, a half day pays half the day's rate (rounded to the cent), and a day with
 * no attendance row is not paid at all. See docs/PAYROLL.md.
 */
enum AttendanceStatus: string
{
    case FullDay = 'FULL_DAY';
    case HalfDay = 'HALF_DAY';
    case Leave = 'LEAVE';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::FullDay => 'Full day',
            self::HalfDay => 'Half day',
            self::Leave => 'Leave',
        });
    }

    /**
     * What a day with this status earns at the given daily rate, in rupees and cents.
     */
    public function payFor(string $dailyRate): string
    {
        return match ($this) {
            self::FullDay => DecimalMath::money($dailyRate),
            self::HalfDay => DecimalMath::money(DecimalMath::div($dailyRate, '2')),
            self::Leave => DecimalMath::money('0'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
