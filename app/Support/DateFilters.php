<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Date filters the database can answer from an index.
 *
 * `whereDate()` wraps the column in DATE(…), which stops MariaDB using an index on it: every
 * report, list period and balance "as of" a day then reads the whole table. These compare the
 * plain column with day boundaries instead, so they work for DATE and DATETIME / TIMESTAMP
 * columns alike and stay fast as the history grows:
 *
 *   whereOnOrAfter('d', $day)   d >= day
 *   whereOnOrBefore('d', $day)  d <  day + 1   (the whole of that day counts)
 *   whereBeforeDay('d', $day)   d <  day
 *   whereAfterDay('d', $day)    d >= day + 1
 *   whereOnDay('d', $day)       day <= d < day + 1
 *
 * Available on query and Eloquent builders. Use these, never whereDate(), on any table that grows.
 */
final class DateFilters
{
    public static function register(): void
    {
        Builder::macro('whereOnOrAfter', function (string $column, DateTimeInterface|string $day): Builder {
            /** @var Builder $this */
            return $this->where($column, '>=', DateFilters::day($day));
        });

        Builder::macro('whereOnOrBefore', function (string $column, DateTimeInterface|string $day): Builder {
            /** @var Builder $this */
            return $this->where($column, '<', DateFilters::nextDay($day));
        });

        Builder::macro('whereBeforeDay', function (string $column, DateTimeInterface|string $day): Builder {
            /** @var Builder $this */
            return $this->where($column, '<', DateFilters::day($day));
        });

        Builder::macro('whereAfterDay', function (string $column, DateTimeInterface|string $day): Builder {
            /** @var Builder $this */
            return $this->where($column, '>=', DateFilters::nextDay($day));
        });

        Builder::macro('whereOnDay', function (string $column, DateTimeInterface|string $day): Builder {
            /** @var Builder $this */
            return $this->where($column, '>=', DateFilters::day($day))->where($column, '<', DateFilters::nextDay($day));
        });
    }

    /** The day as YYYY-MM-DD — the calendar day it names, whatever time it carries. */
    public static function day(DateTimeInterface|string $day): string
    {
        return $day instanceof DateTimeInterface ? $day->format('Y-m-d') : Carbon::parse($day)->toDateString();
    }

    public static function nextDay(DateTimeInterface|string $day): string
    {
        return Carbon::parse(self::day($day))->addDay()->toDateString();
    }
}
