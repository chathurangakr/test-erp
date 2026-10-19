<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\KioskOptionsController;
use App\Http\Controllers\Api\V1\MetaController;
use Illuminate\Support\Facades\Route;

/*
 * The versioned REST API, for the mobile app. Everything lives under /api/v1; a future v2 is
 * added beside it, never in place of it. See docs/API_V1.md.
 *
 * Only sign-in is public. Every other route requires, in order: a valid bearer token carrying
 * the mobile-api ability, an active user, the API rate limit — and then exactly ONE
 * permission from App\Enums\Permission, the same permission the matching web route checks.
 * The only routes without a permission are the user's own session endpoints, meta, the
 * dashboard and the kiosk options (which filter their content by permission). ApiRouteAuthorizationTest fails if a
 * route breaks this.
 *
 * Controllers here are thin: validate, call the same domain action the website uses, return a
 * resource. No business rule is implemented twice.
 */
Route::prefix('v1')->name('api.v1.')->middleware('api.https')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware(['throttle:api-login', 'api.locale'])
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'abilities:'.config('api.token_ability'), 'api.active', 'api.locale', 'throttle:api'])
        ->group(function () {
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
            Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

            Route::get('meta', MetaController::class)->name('meta');
            Route::get('dashboard', DashboardController::class)->name('dashboard');
            // The offline app's simple-mode data; like the dashboard, filtered by permission inside.
            Route::get('kiosk/options', KioskOptionsController::class)->name('kiosk.options');

            require __DIR__.'/api/v1/masters.php';
            require __DIR__.'/api/v1/inventory.php';
            require __DIR__.'/api/v1/purchasing.php';
            require __DIR__.'/api/v1/sales.php';
            require __DIR__.'/api/v1/production.php';
            require __DIR__.'/api/v1/payables_receivables.php';
            require __DIR__.'/api/v1/cash.php';
            require __DIR__.'/api/v1/reports.php';
            require __DIR__.'/api/v1/payroll.php';
        });
});
