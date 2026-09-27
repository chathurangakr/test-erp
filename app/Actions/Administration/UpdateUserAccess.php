<?php

namespace App\Actions\Administration;

use App\Models\User;
use App\Services\Authorization\AdministratorGuard;

/**
 * Sets which roles a user holds and whether they may sign in.
 *
 * Applied inside one transaction and checked afterwards: if the change would leave the system
 * with no active administrator, it is refused and nothing is saved — including when the person
 * making the change is the administrator in question.
 *
 * The user's name, email and password are theirs to manage through their own settings; this
 * action never touches them, and there is no way to see or set a password here.
 */
class UpdateUserAccess
{
    public function __construct(private readonly AdministratorGuard $guard) {}

    /**
     * @param  list<int>  $roleIds
     */
    public function handle(User $user, array $roleIds, bool $isActive, ?bool $startInSimpleMode = null): User
    {
        return $this->guard->protect(function () use ($user, $roleIds, $isActive, $startInSimpleMode): User {
            $user->forceFill(array_filter([
                'is_active' => $isActive,
                // A landing-page preference only; it grants nothing.
                'start_in_simple_mode' => $startInSimpleMode,
            ], fn ($value): bool => $value !== null))->save();
            $user->roles()->sync($roleIds);
            $user->forgetPermissions();

            // A deactivated user loses every mobile session at once, not at token expiry.
            if (! $isActive) {
                $user->tokens()->delete();
            }

            return $user->refresh();
        }, $isActive ? 'roles' : 'is_active');
    }
}
