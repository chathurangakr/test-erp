<?php

namespace App\Services\Authorization;

use App\Enums\Permission as PermissionCode;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the `permissions` table and the ADMIN role in step with `App\Enums\Permission`.
 *
 * Idempotent: running it twice changes nothing the second time. It never removes a permission
 * from any role except ADMIN, whose permission set is always the complete catalogue — that is
 * what makes "at least one active administrator" a real guarantee against lockout.
 */
class PermissionCatalogue
{
    /**
     * Mirror every catalogue entry into the table, and give ADMIN all of them.
     */
    public function sync(): Role
    {
        return DB::transaction(function (): Role {
            foreach (PermissionCode::cases() as $case) {
                Permission::query()->updateOrCreate(
                    ['code' => $case->value],
                    ['name' => $case->label(), 'module' => $case->module()],
                );
            }

            return $this->syncAdministratorRole();
        });
    }

    /**
     * The ADMIN role, created if missing, holding every permission and always active.
     */
    public function syncAdministratorRole(): Role
    {
        $admin = Role::query()->firstOrCreate(
            ['code' => Role::ADMINISTRATOR],
            [
                'name' => 'Administrator',
                'description' => 'Every permission, always. Cannot be deactivated or reduced.',
                'is_active' => true,
            ],
        );

        if (! $admin->is_active) {
            $admin->forceFill(['is_active' => true])->save();
        }

        $admin->permissions()->sync(Permission::query()->pluck('id'));

        return $admin;
    }
}
