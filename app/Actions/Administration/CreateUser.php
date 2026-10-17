<?php

namespace App\Actions\Administration;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds an account for someone, with the roles it starts with — the administrator's alternative
 * to self-registration, and the only way in when sign-up is closed.
 *
 * The account is active at once. Its password is a starting one the administrator passes on;
 * the person changes it in their own settings. Adding an account can never remove an
 * administrator, so AdministratorGuard has nothing to protect here.
 */
class CreateUser
{
    /**
     * @param  array{name: string, email: string, password: string}  $profile
     * @param  list<int>  $roleIds
     */
    public function handle(array $profile, array $roleIds, bool $startInSimpleMode = false): User
    {
        return DB::transaction(function () use ($profile, $roleIds, $startInSimpleMode): User {
            $user = new User($profile);
            // A landing-page preference only; it grants nothing.
            $user->start_in_simple_mode = $startInSimpleMode;
            $user->save();
            $user->roles()->sync($roleIds);

            return $user->refresh();
        });
    }
}
