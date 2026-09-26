<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\Request;

/**
 * One page-size rule for every collection endpoint.
 *
 * `per_page` defaults to config('api.pagination.default_per_page') and is capped at
 * config('api.pagination.max_per_page'), so no client can ask for a whole ledger at once.
 */
trait PaginatesResults
{
    protected function perPage(Request $request): int
    {
        $requested = $request->integer('per_page', (int) config('api.pagination.default_per_page'));

        return max(1, min($requested, (int) config('api.pagination.max_per_page')));
    }
}
