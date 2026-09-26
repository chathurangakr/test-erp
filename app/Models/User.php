<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission as PermissionCode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * A new user is active — but holds no role, and so no permission, until an administrator
     * grants one. Self-registration never grants access.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * This request's effective permission codes, once computed.
     *
     * @var list<string>|null
     */
    private ?array $permissionCodeCache = null;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Every permission this user holds: the union of the permissions of their ACTIVE roles.
     *
     * An inactive user holds nothing, whatever their roles say, and an inactive role grants
     * nothing to anyone. Computed once per request and remembered on the model.
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        if ($this->permissionCodeCache !== null) {
            return $this->permissionCodeCache;
        }

        if (! $this->is_active || ! $this->exists) {
            return $this->permissionCodeCache = [];
        }

        return $this->permissionCodeCache = Permission::query()
            ->whereHas('roles', fn ($role) => $role
                ->where('is_active', true)
                ->whereHas('users', fn ($user) => $user->whereKey($this->getKey())))
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    public function hasPermission(PermissionCode|string $permission): bool
    {
        $code = $permission instanceof PermissionCode ? $permission->value : $permission;

        return in_array($code, $this->permissionCodes(), true);
    }

    /**
     * Whether this user is an active holder of the active ADMIN role.
     *
     * Used only by the lockout safety rules — never to authorize a business action, which
     * always goes through a permission.
     */
    public function isAdministrator(): bool
    {
        return $this->is_active && $this->roles()
            ->where('code', Role::ADMINISTRATOR)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Drop the remembered permissions after this user's roles or status change.
     */
    public function forgetPermissions(): void
    {
        $this->permissionCodeCache = null;
    }
}
