<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the app needs to configure itself: the API version, the base currency, and which
 * modules this user can use. No configuration secret is ever returned.
 */
class MetaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $permissions = $request->user()->permissionCodes();

        $modules = collect(Permission::cases())
            ->filter(fn (Permission $permission): bool => in_array($permission->value, $permissions, true))
            ->map(fn (Permission $permission): string => $permission->module())
            ->unique()
            ->values();

        return response()->json([
            'data' => [
                'api_version' => config('api.version'),
                'base_currency' => config('accounting.base_currency'),
                'server_time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'timezone' => 'UTC',
                'pagination' => [
                    'default_per_page' => (int) config('api.pagination.default_per_page'),
                    'max_per_page' => (int) config('api.pagination.max_per_page'),
                ],
                'modules' => $modules,
                'permissions' => $permissions,
            ],
        ]);
    }
}
