<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the full ERP in the signed-in person's chosen language.
 *
 * The choice is saved on the user, so it follows them to any device. Before signing in (the
 * login page) a cookie remembers it. Simple mode's own middleware (SetKioskLocale) runs later
 * on /kiosk and uses the device's choice instead, because kiosk tablets are shared.
 *
 * Display only: saved records are always written in English through App\Support\RecordText.
 */
class SetLocale
{
    public const COOKIE = 'erp_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->cookie(self::COOKIE) ?? config('app.locale');

        App::setLocale(in_array($locale, config('app.locales'), true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
