<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses API calls made over plain HTTP when `api.require_https` is on (production).
 *
 * A bearer token sent over HTTP can be read by anyone on the network, so the request is
 * refused rather than redirected — a redirect would already have leaked the token.
 */
class RequireHttpsForApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('api.require_https') && ! $request->secure()) {
            return response()->json([
                'message' => __('The API is only available over HTTPS.'),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
