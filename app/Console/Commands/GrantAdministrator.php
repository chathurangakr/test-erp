<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Authorization\PermissionCatalogue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes an existing user an administrator, from the server console.
 *
 * For a fresh installation, where the seeder's bootstrap found no users to promote: register
 * the first account through the website, then run this on the server. It requires shell access
 * to the machine, which is exactly the trust an administrator grant deserves — there is no web
 * route that can do this for a user who is not already an administrator.
 */
#[Signature('authorization:grant-admin {email : The email address of an existing user}')]
#[Description('Give an existing user the Administrator role (server console only)')]
class GrantAdministrator extends Command
{
    public function handle(PermissionCatalogue $catalogue): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email address. Register the account first.');

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error('That user is deactivated. Reactivate them before granting administrator access.');

            return self::FAILURE;
        }

        $admin = $catalogue->sync();
        $user->roles()->syncWithoutDetaching([$admin->id]);

        $this->info("{$user->name} <{$user->email}> is now an administrator.");

        return self::SUCCESS;
    }
}
