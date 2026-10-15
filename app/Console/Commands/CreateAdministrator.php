<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Authorization\PermissionCatalogue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the first administrator account from the server console — for an unattended
 * installation, where nobody opens the website to register first.
 *
 * The password is read from the COCONUT_ADMIN_PASSWORD environment variable (never a command
 * argument, which other users of the machine could read in the process list), or asked for when
 * the command is run by hand. It must satisfy the same rules as the registration screen.
 *
 * Safe to re-run: if the account already exists it is only made an administrator — its password
 * is left alone. Like authorization:grant-admin, it needs shell access to the machine, which is
 * exactly the trust an administrator grant deserves.
 */
#[Signature('authorization:create-admin
    {email : Email address the administrator signs in with}
    {--name=Administrator : Display name}')]
#[Description('Create (or promote) the administrator account from the server console')]
class CreateAdministrator extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    public function handle(PermissionCatalogue $catalogue): int
    {
        $email = (string) $this->argument('email');
        $existing = User::query()->where('email', $email)->first();
        $password = '';

        if ($existing === null) {
            $password = (string) (getenv('COCONUT_ADMIN_PASSWORD') ?: ($this->input->isInteractive() ? $this->secret('Password') : ''));

            $validator = Validator::make(
                ['name' => (string) $this->option('name'), 'email' => $email, 'password' => $password, 'password_confirmation' => $password],
                [...$this->profileRules(), 'password' => $this->passwordRules()],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }
        } elseif (! $existing->is_active) {
            $this->error('That user is deactivated. Reactivate them before granting administrator access.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($existing, $email, $password, $catalogue): User {
            $user = $existing ?? User::create([
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => $password,
            ]);

            // Created by someone with shell access to the server, so the address is trusted.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->roles()->syncWithoutDetaching([$catalogue->sync()->id]);

            return $user;
        });

        $this->info($existing === null
            ? "Administrator {$user->email} created."
            : "{$user->email} already existed; it is now an administrator (password unchanged).");

        return self::SUCCESS;
    }
}
