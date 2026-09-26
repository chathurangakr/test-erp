<?php

namespace App\Services\Authorization;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The system must never be left without an administrator.
 *
 * "Administrator" means an ACTIVE user holding the ACTIVE ADMIN role. Every change that could
 * reduce that number — removing someone's ADMIN role, deactivating them, deleting an account —
 * runs through `protect()`, which makes the change and then compares the count before and
 * after inside the same database transaction. If there was an administrator before and none
 * after, the change is refused and rolled back. Checking the outcome rather than guessing at
 * the input means the rule holds however the change was made, including an administrator
 * editing themselves.
 *
 * The ADMIN role row is locked first, so two administrators demoting each other at the same
 * moment cannot both succeed.
 */
class AdministratorGuard
{
    /**
     * Run a change, refusing it if it would leave no active administrator.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     *
     * @throws ValidationException when the change would remove the last administrator
     */
    public function protect(callable $change, string $field = 'roles'): mixed
    {
        return DB::transaction(function () use ($change, $field): mixed {
            Role::query()->where('code', Role::ADMINISTRATOR)->lockForUpdate()->first();

            $before = $this->activeAdministratorCount();
            $result = $change();

            if ($before > 0 && $this->activeAdministratorCount() === 0) {
                throw ValidationException::withMessages([
                    $field => __('At least one active user must keep the Administrator role, or nobody could manage access. Make another user an administrator first.'),
                ]);
            }

            return $result;
        });
    }

    public function activeAdministratorCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($role) => $role
                ->where('code', Role::ADMINISTRATOR)
                ->where('is_active', true))
            ->count();
    }
}
