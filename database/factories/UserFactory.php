<?php

namespace Database\Factories;

use App\Enums\Permission as PermissionCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\PermissionCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * A deactivated user: cannot sign in and holds no permissions.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Holds the ADMIN role, and so every permission.
     *
     * Business-feature tests use this so they test the feature, not the access rules; the
     * access rules have their own tests. A plain factory user holds no role at all.
     */
    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->roles()->syncWithoutDetaching([app(PermissionCatalogue::class)->sync()->id]);
            $user->forgetPermissions();
        });
    }

    /**
     * Holds exactly these permissions, through a role made just for them.
     */
    public function withPermissions(PermissionCode ...$permissions): static
    {
        return $this->afterCreating(function (User $user) use ($permissions): void {
            app(PermissionCatalogue::class)->sync();

            $role = Role::query()->create([
                'code' => 'TEST_'.strtoupper(Str::random(8)),
                'name' => 'Test role',
                'is_active' => true,
            ]);

            $codes = array_map(fn (PermissionCode $permission): string => $permission->value, $permissions);
            $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id'));

            $user->roles()->syncWithoutDetaching([$role->id]);
            $user->forgetPermissions();
        });
    }

    /**
     * Holds these seeded roles by code. Seed RolesAndPermissionsSeeder first.
     */
    public function withRoles(string ...$codes): static
    {
        return $this->afterCreating(function (User $user) use ($codes): void {
            $user->roles()->syncWithoutDetaching(Role::query()->whereIn('code', $codes)->pluck('id'));
            $user->forgetPermissions();
        });
    }
}
