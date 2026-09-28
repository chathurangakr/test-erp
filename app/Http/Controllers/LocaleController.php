<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * The one-tap language switch.
 *
 * Saves the choice on the signed-in user (it follows them to every device) and in a cookie
 * for the sign-in screens. Open to guests so the login page can be switched too; it changes
 * nothing but the display language.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(config('app.locales'))]])['locale'];

        $request->user()?->forceFill(['locale' => $locale])->save();

        Cookie::queue(SetLocale::COOKIE, $locale, 60 * 24 * 365);

        return back();
    }
}
