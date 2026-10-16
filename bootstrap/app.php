<?php

use App\Http\Middleware\EnsureApiUserIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireHttpsForApi;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetKioskLocale;
use App\Http\Middleware\SetLocale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'kiosk_locale', 'erp_locale']);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'api.active' => EnsureApiUserIsActive::class,
            'api.https' => RequireHttpsForApi::class,
            'api.locale' => SetApiLocale::class,
            'kiosk.locale' => SetKioskLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API clients get a stable JSON shape — {"message": ..., "errors": {...}} — and
        // never a model class name, SQL or stack trace. See docs/API_V1.md §6.
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = $exception->errors();

            // Every domain action reports a document-state conflict ("already posted",
            // "only a draft can be…") under the `status` key. That is a 409, not bad input.
            $isStateConflict = array_keys($errors) === ['status'];

            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $errors,
            ], $isStateConflict ? 409 : $exception->status);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $isMissingRecord = $exception->getPrevious() instanceof ModelNotFoundException;

            return response()->json([
                'message' => $isMissingRecord ? __('The requested record was not found.') : __('Not found.'),
            ], 404);
        });
    })->create();
