<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Device sign-in, tokens and sign-out — with real tokens, not Sanctum::actingAs().
 */
class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function login(User $user, array $extra = []): TestResponse
    {
        return $this->postJson(route('api.v1.auth.login'), array_merge([
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Test iPhone',
        ], $extra));
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    #[Test]
    public function an_active_user_signs_in_and_receives_a_bearer_token_and_safe_profile(): void
    {
        $user = User::factory()->withRoles('STOREKEEPER')->create();

        $response = $this->login($user)->assertCreated();

        $response->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email', 'roles', 'permissions']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.roles.0.code', 'STOREKEEPER')
            ->assertJsonPath('user.permissions', $user->permissionCodes());

        $this->assertMatchesRegularExpression('/^\d+\|cerp_/', $response->json('token'));
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $response->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
    }

    #[Test]
    public function the_token_is_stored_hashed_carries_only_the_mobile_ability_and_expires_in_90_days(): void
    {
        $user = User::factory()->create();

        $plain = $this->login($user)->json('token');
        $token = PersonalAccessToken::query()->sole();

        $this->assertNotSame(explode('|', $plain, 2)[1], $token->token, 'The database must hold a hash, not the token.');
        $this->assertSame(['mobile-api'], $token->abilities);
        $this->assertSame('Test iPhone', $token->name);
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, $token->expires_at->timestamp, 5);
    }

    #[Test]
    public function a_wrong_password_is_rejected_with_a_validation_error(): void
    {
        $user = User::factory()->create();

        $this->login($user, ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(0, PersonalAccessToken::count());
    }

    #[Test]
    public function an_inactive_user_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->login($user)->assertForbidden()->assertJsonStructure(['message']);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    #[Test]
    public function device_name_is_required_and_bounded(): void
    {
        $user = User::factory()->create();

        $this->login($user, ['device_name' => ''])->assertJsonValidationErrors('device_name');
        $this->login($user, ['device_name' => str_repeat('x', 101)])->assertJsonValidationErrors('device_name');
    }

    #[Test]
    public function sign_in_is_rate_limited(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear(mb_strtolower($user->email).'|127.0.0.1');

        foreach (range(1, config('api.rate_limits.login_per_minute')) as $attempt) {
            $this->login($user, ['password' => 'wrong'])->assertUnprocessable();
        }

        $this->login($user)->assertStatus(429);
    }

    #[Test]
    public function a_user_with_two_factor_must_supply_a_code(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        // A real base32 secret, so Fortify's verifier actually runs.
        $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();

        $this->login($user)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->login($user, ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->login($user, ['recovery_code' => 'recovery-code-1'])->assertCreated();
        $this->assertNotContains('recovery-code-1', $user->fresh()->recoveryCodes(), 'A recovery code works once.');
    }

    #[Test]
    public function the_token_authenticates_and_me_returns_roles_and_permissions(): void
    {
        $user = User::factory()->withRoles('PRODUCTION', 'SALES')->create();
        $token = $this->login($user)->json('token');

        $this->getJson(route('api.v1.auth.me'), $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.permissions', $user->permissionCodes())
            ->assertJsonCount(2, 'data.roles');
    }

    #[Test]
    public function logout_revokes_only_the_current_device(): void
    {
        $user = User::factory()->create();
        $phone = $this->login($user)->json('token');
        $tablet = $this->login($user, ['device_name' => 'Tablet'])->json('token');

        $this->postJson(route('api.v1.auth.logout'), [], $this->bearer($phone))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson(route('api.v1.auth.me'), $this->bearer($phone))->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson(route('api.v1.auth.me'), $this->bearer($tablet))->assertOk();
    }

    #[Test]
    public function logout_all_revokes_every_device(): void
    {
        $user = User::factory()->create();
        $phone = $this->login($user)->json('token');
        $tablet = $this->login($user, ['device_name' => 'Tablet'])->json('token');

        $this->postJson(route('api.v1.auth.logout-all'), [], $this->bearer($phone))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson(route('api.v1.auth.me'), $this->bearer($tablet))->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $this->login($user)->json('token');

        $this->travel(91)->days();

        $this->getJson(route('api.v1.auth.me'), $this->bearer($token))->assertUnauthorized();
    }

    #[Test]
    public function a_token_without_the_mobile_ability_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('script', ['something-else'])->plainTextToken;

        $this->getJson(route('api.v1.auth.me'), $this->bearer($token))->assertForbidden();
    }

    #[Test]
    public function deactivating_a_user_revokes_their_tokens_and_an_old_token_is_refused(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();
        $user = User::factory()->withRoles('SALES')->create();
        $token = $this->login($user)->json('token');

        $this->actingAs($admin)->put(route('users.update', $user), ['is_active' => false, 'role_ids' => []]);

        $this->getJson(route('api.v1.auth.me'), $this->bearer($token))->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function a_user_marked_inactive_by_other_means_is_refused_on_the_next_call(): void
    {
        $user = User::factory()->withRoles('SALES')->create();
        $token = $this->login($user)->json('token');

        User::whereKey($user->id)->update(['is_active' => false]);

        $this->getJson(route('api.v1.auth.me'), $this->bearer($token))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This account has been deactivated. Please contact an administrator.');
    }

    #[Test]
    public function changing_the_password_signs_out_every_device(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        $this->login($user, ['device_name' => 'Tablet']);

        $user->update(['password' => 'a-new-password']);

        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function there_is_no_api_registration(): void
    {
        $this->postJson('/api/v1/register', ['email' => 'x@example.com'])->assertNotFound();
        $this->postJson('/api/v1/auth/register', ['email' => 'x@example.com'])->assertNotFound();
    }

    #[Test]
    public function a_browser_session_does_not_authenticate_the_api(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();

        $this->actingAs($admin, 'web')->getJson(route('api.v1.auth.me'))->assertUnauthorized();
    }

    #[Test]
    public function meta_reports_version_currency_and_modules_without_secrets(): void
    {
        $user = User::factory()->withPermissions(Permission::ProductsView, Permission::ProductionPost)->create();
        $token = $this->login($user)->json('token');

        $response = $this->getJson(route('api.v1.meta'), $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.base_currency', config('accounting.base_currency'))
            ->assertJsonPath('data.modules', ['Master Data', 'Production']);

        $this->assertStringNotContainsString((string) config('app.key'), $response->getContent());
    }
}
