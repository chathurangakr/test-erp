<?php

namespace App\Actions\Administration;

use App\Models\Role;
use App\Services\Authorization\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a role and its permission set.
 *
 * Permissions are chosen from the existing catalogue only — the request carries permission
 * ids, validated against the table the catalogue seeds. A role's code is fixed once created.
 *
 * ADMIN is protected: it always holds every permission and is always active, because it is
 * the guarantee that someone can always manage access. Its name and description may change;
 * nothing else about it may.
 */
class SaveRole
{
    public function __construct(private readonly PermissionCatalogue $catalogue) {}

    /**
     * @param  array{code?: string, name: string, description?: string|null, is_active: bool}  $attributes
     * @param  list<int>  $permissionIds
     */
    public function handle(?Role $role, array $attributes, array $permissionIds): Role
    {
        return DB::transaction(function () use ($role, $attributes, $permissionIds): Role {
            $role ??= new Role(['code' => $attributes['code'] ?? '']);

            if ($role->exists && $role->isAdministrator()) {
                if (! $attributes['is_active']) {
                    throw ValidationException::withMessages([
                        'is_active' => __('The Administrator role cannot be deactivated.'),
                    ]);
                }

                $role->fill(['name' => $attributes['name'], 'description' => $attributes['description'] ?? null])->save();

                // Whatever was submitted, ADMIN keeps the whole catalogue.
                return $this->catalogue->syncAdministratorRole();
            }

            $role->fill([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'is_active' => $attributes['is_active'],
            ])->save();

            $role->permissions()->sync($permissionIds);

            return $role->refresh();
        });
    }
}
