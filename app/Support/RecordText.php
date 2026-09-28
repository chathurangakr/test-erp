<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Support\Facades\App;

/**
 * Text that is SAVED into a business record — journal and ledger descriptions, lot notes.
 *
 * Always rendered in the record language (English), whatever language the person posting is
 * using on screen. Otherwise the same kind of ledger line would be stored in Sinhala by one
 * user and English by another, and reports, searches and audits would stop agreeing. Screens
 * may translate these stored texts when displaying them; the stored value never changes.
 *
 * Use this — never __() — for any string written to the database. See docs/LOCALISATION.md.
 */
final class RecordText
{
    public const LOCALE = 'en';

    /**
     * The text, in the record language.
     *
     * A replacement may be an enum with a label() — pass the enum itself, not its label, and
     * it is named in the record language too (a type or status label shown on screen may be
     * translated; the one saved must not be).
     *
     * @param  array<string, mixed>  $replace
     */
    public static function of(string $key, array $replace = []): string
    {
        return self::inRecordLocale(function () use ($key, $replace): string {
            foreach ($replace as $name => $value) {
                if ($value instanceof BackedEnum && method_exists($value, 'label')) {
                    $replace[$name] = $value->label();
                }
            }

            return (string) __($key, $replace);
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function inRecordLocale(callable $callback): mixed
    {
        $screenLocale = App::getLocale();
        App::setLocale(self::LOCALE);

        try {
            return $callback();
        } finally {
            App::setLocale($screenLocale);
        }
    }
}
