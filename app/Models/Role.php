<?php

namespace App\Models;

use App\Concerns\HasUppercaseCode;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A named bundle of permissions.
 *
 * Authorization never asks which role a user holds — only which permissions their active
 * roles grant between them. The one exception is the ADMIN role, which the lockout safety
 * rules must be able to recognise: it always holds every permission, cannot be deactivated,
 * and at least one active user must always hold it. See docs/AUTHORIZATION_RULES.md.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'is_active'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasUppercaseCode;

    /**
     * The administrator role's code — the only role code the application knows by name, and
     * only so it can refuse to let the system lose its last administrator.
     */
    public const ADMINISTRATOR = 'ADMIN';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function isAdministrator(): bool
    {
        return $this->code === self::ADMINISTRATOR;
    }
}
