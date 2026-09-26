<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an API token whose user has been deactivated.
 *
 * Deactivation deletes the user's tokens already, so this is the second line: a token that
 * somehow survives still grants nothing. An inactive user also holds no permissions, so even
 * without this every business endpoint would answer 403 — this makes the answer an honest 401
 * and removes the token.
 */
class EnsureApiUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => __('This account has been deactivated. Please contact an administrator.'),
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
