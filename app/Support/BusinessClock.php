<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The factory's local calendar and clock.
 *
 * Stored moments are UTC (config('app.timezone')). But "today", a document number's date
 * stamp, whether an invoice is overdue and the times people read on screen must follow the
 * factory's own timezone (config('app.business_timezone'), Asia/Colombo) — otherwise work done
 * between midnight and 05:30 in Sri Lanka is dated the previous day.
 *
 * Use this for DATES and DISPLAY only. Never save the result of now() from here into a
 * timestamp column: it would store Sri Lankan wall-clock time in a UTC column.
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Colombo');
    }

    /**
     * The current moment, expressed in the business timezone.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * Today's calendar date in the business timezone, at midnight local time.
     */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * Today's date as YYYY-MM-DD, for date columns, filters and form defaults.
     */
    public static function todayString(): string
    {
        return self::today()->toDateString();
    }

    /**
     * Whether a due date has passed, judged by the business calendar.
     *
     * Compares calendar dates, not instants, so the answer never depends on the server's
     * timezone: an invoice due on the 26th is overdue from the 27th in Sri Lanka.
     */
    public static function isPastDue(?CarbonInterface $dueDate): bool
    {
        return $dueDate !== null && $dueDate->toDateString() < self::todayString();
    }

    /**
     * Whether a calendar date lies after today in the business calendar.
     *
     * Compares calendar dates, not instants: a date string parsed in the app timezone (UTC) is
     * midnight UTC, which is already 05:30 in Sri Lanka — later than BusinessClock::today()'s
     * midnight — so comparing the instants would call today "the future".
     */
    public static function isFuture(CarbonInterface|string $date): bool
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : CarbonImmutable::parse($date)->toDateString();

        return $day > self::todayString();
    }

    /**
     * A stored UTC moment as people should read it on screen, in the business timezone.
     */
    public static function display(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->setTimezone(self::timezone())->format('Y-m-d H:i:s');
    }
}
