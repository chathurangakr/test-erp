<?php

namespace App\Actions\Administration;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a role that nobody holds.
 *
 * A role still assigned to anyone is refused — deactivate it instead, which withdraws its
 * permissions at once while leaving the assignments visible. ADMIN can never be deleted.
 * The database backs this up: `role_user.role_id` restricts deletion.
 */
class DeleteRole
{
    public function handle(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            $role = Role::query()->lockForUpdate()->findOrFail($role->getKey());

            if ($role->isAdministrator()) {
                throw ValidationException::withMessages([
                    'role' => __('The Administrator role cannot be deleted.'),
                ]);
            }

            if ($role->users()->exists()) {
                throw ValidationException::withMessages([
                    'role' => __('This role is still assigned to users. Remove it from them, or deactivate the role instead.'),
                ]);
            }

            $role->permissions()->detach();
            $role->delete();
        });
    }
}
