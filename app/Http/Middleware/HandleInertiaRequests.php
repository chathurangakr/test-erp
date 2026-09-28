<?php

namespace App\Http\Middleware;

use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // The effective permission codes, so screens can hide what the user cannot
                // use. Convenience only — every route checks the permission again on the
                // server, which is the real authority.
                'permissions' => $request->user()?->permissionCodes() ?? [],
            ],
            // Today's date in the factory's timezone, so forms default to the Sri Lankan date
            // even between midnight and 05:30, when the UTC date is still yesterday.
            'businessToday' => BusinessClock::todayString(),
            // The screen language; translations themselves ship with the frontend build.
            'locale' => App::getLocale(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
