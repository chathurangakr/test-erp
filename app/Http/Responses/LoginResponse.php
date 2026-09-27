<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Where a user lands after signing in — password, two-factor or passkey.
 *
 * Staff an administrator has marked "start in simple mode" go straight to the big-button
 * screens; everyone else goes to the usual home. A page the user was trying to reach before
 * signing in still takes priority, exactly as Fortify's own response does.
 */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $home = $request->user()?->start_in_simple_mode ? route('kiosk.home') : Fortify::redirects('login');

        return redirect()->intended($home);
    }
}
