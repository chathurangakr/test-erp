<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a user who has been deactivated, on their very next request.
 *
 * The login screen refuses an inactive user, but a session opened before the deactivation —
 * or a remember-me cookie, or a passkey — would otherwise carry on. An inactive user also
 * holds no permissions, so even a request that slipped past this would be refused; this makes
 * the outcome a clean sign-out rather than a wall of 403s.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('This account has been deactivated. Please contact an administrator.'),
            ]);
        }

        return $next($request);
    }
}
