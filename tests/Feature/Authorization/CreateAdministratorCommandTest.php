<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * authorization:create-admin — the unattended installer's way to create the first
 * administrator. The password comes from COCONUT_ADMIN_PASSWORD, never the command line.
 */
class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        putenv('COCONUT_ADMIN_PASSWORD');

        parent::tearDown();
    }

    #[Test]
    public function it_creates_a_verified_administrator_from_the_environment_password(): void
    {
        putenv('COCONUT_ADMIN_PASSWORD=Factory-Owner-2026');

        $this->artisan('authorization:create-admin', ['email' => 'owner@example.com', '--name' => 'Owner', '--no-interaction' => true])
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertSame('Owner', $user->name);
        $this->assertTrue($user->isAdministrator());
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Factory-Owner-2026', $user->password));
    }

    #[Test]
    public function running_it_again_promotes_without_touching_the_password(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'password' => 'Original-Pass-1']);
        putenv('COCONUT_ADMIN_PASSWORD=Something-Else-2');

        $this->artisan('authorization:create-admin', ['email' => 'owner@example.com', '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->isAdministrator());
        $this->assertTrue(Hash::check('Original-Pass-1', $user->fresh()->password));
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function it_refuses_a_missing_password_or_a_bad_email_and_creates_nothing(): void
    {
        $this->artisan('authorization:create-admin', ['email' => 'owner@example.com', '--no-interaction' => true])
            ->assertFailed();

        putenv('COCONUT_ADMIN_PASSWORD=Factory-Owner-2026');

        $this->artisan('authorization:create-admin', ['email' => 'not-an-email', '--no-interaction' => true])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function a_deactivated_user_is_not_promoted(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'gone@example.com']);

        $this->artisan('authorization:create-admin', ['email' => 'gone@example.com', '--no-interaction' => true])
            ->assertFailed();

        $this->assertFalse($user->fresh()->isAdministrator());
    }
}
