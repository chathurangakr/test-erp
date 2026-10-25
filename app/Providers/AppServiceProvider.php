<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\MailSetting;
use App\Models\User;
use App\Observers\TransactionAlertObserver;
use App\Services\Telegram\TransactionAlertCatalog;
use App\Support\DateFilters;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DateFilters::register();

        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureApiRateLimiting();
        $this->configureMail();
        $this->configureTransactionAlerts();
    }

    /**
     * Every reported document queues a Telegram alert when it is posted or reversed.
     * See App\Services\Telegram\TransactionAlertCatalog and docs/TELEGRAM.md.
     */
    private function configureTransactionAlerts(): void
    {
        foreach (array_keys(TransactionAlertCatalog::MODELS) as $model) {
            $model::observe(TransactionAlertObserver::class);
        }
    }

    /**
     * The email account saved on Administration → Email settings, applied when the mailer is
     * first needed (so a page that sends nothing reads nothing). Until it is switched on, the
     * .env settings stand. Before migrations have run there is no table, and .env applies too.
     */
    private function configureMail(): void
    {
        $this->app->resolving('mail.manager', function (): void {
            try {
                MailSetting::current()->applyToConfig();
            } catch (QueryException) {
                // Not migrated yet: keep the .env settings.
            }
        });
    }

    /**
     * API throttles, sized in config/api.php.
     *
     * Sign-in is limited per email and IP together, so one attacker cannot lock out a user
     * from every device; everything else is limited per signed-in user.
     */
    protected function configureApiRateLimiting(): void
    {
        RateLimiter::for('api-login', fn (Request $request): Limit => Limit::perMinute(config('api.rate_limits.login_per_minute'))
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(config('api.rate_limits.requests_per_minute'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Register every catalogue permission as a Gate ability.
     *
     * Routes then declare `->can(Permission::X)` and Laravel answers 403 on its own. There is
     * deliberately no `Gate::before()` super-user bypass: ADMIN passes because it holds every
     * permission, not because it skips the check. See docs/AUTHORIZATION_RULES.md.
     */
    protected function configureAuthorization(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }

        // A refused request gets a proper page inside the app, not a bare error screen or a
        // redirect loop. JSON callers still receive a plain 403.
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            if ($response->statusCode() !== 403 || $response->request->expectsJson() || $response->request->is('api/*')) {
                return null;
            }

            return $response->render('ErrorPage', ['status' => 403])->withSharedData();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
