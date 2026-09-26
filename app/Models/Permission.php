<?php

namespace App\Models;

use App\Enums\Permission as PermissionCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * The database mirror of one `App\Enums\Permission` case.
 *
 * Rows exist so roles can reference permissions with foreign keys. They are written only by
 * `RolesAndPermissionsSeeder` from the enum; no screen creates or renames one.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $module
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'module', 'description'])]
class Permission extends Model
{
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * The catalogue entry behind this row, or null if the code is no longer in the catalogue.
     */
    public function definition(): ?PermissionCode
    {
        return PermissionCode::tryFrom($this->code);
    }
}
