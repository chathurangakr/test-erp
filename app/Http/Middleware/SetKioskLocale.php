<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses the language chosen on this device for the simple-mode screens.
 *
 * The choice lives in a cookie so each shared tablet remembers its own language. It sets the
 * application locale, so server messages — validation and business-rule errors — come back in
 * Sinhala too wherever lang/si.json has them; anything untranslated falls back to English.
 */
class SetKioskLocale
{
    public const COOKIE = 'kiosk_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->cookie(self::COOKIE, config('kiosk.default_locale'));

        App::setLocale(in_array($locale, config('kiosk.locales'), true) ? $locale : config('kiosk.default_locale'));

        return $next($request);
    }
}
