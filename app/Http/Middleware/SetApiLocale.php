<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers the mobile app in the person's language: the `Accept-Language` the app sends (the
 * language chosen on the phone), else the signed-in person's own choice (`users.locale`), else
 * the default. Only supported languages are used; anything else falls through.
 *
 * Display only, exactly like the website's SetLocale: messages and labels follow the language,
 * saved records stay English (App\Support\RecordText).
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.locales');
        $requested = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));

        $locale = match (true) {
            in_array($requested, $supported, true) => $requested,
            in_array($request->user()?->locale, $supported, true) => $request->user()->locale,
            default => config('app.locale'),
        };

        App::setLocale($locale);

        return $next($request);
    }
}
