<?php

namespace App\Actions\Administration;

use App\Models\User;
use App\Services\Authorization\AdministratorGuard;
use App\Services\Authorization\UserHistory;
use Illuminate\Validation\ValidationException;

/**
 * A user deleting their own account — permitted only when it is harmless.
 *
 * Refused when the user appears anywhere in business history (they must be deactivated by an
 * administrator instead, so every record keeps naming who did it), and refused when they are
 * the last active administrator. An account that has done nothing yet — a mistaken
 * self-registration, say — may still be deleted by its owner.
 */
class DeleteOwnAccount
{
    public function __construct(
        private readonly UserHistory $history,
        private readonly AdministratorGuard $guard,
    ) {}

    /**
     * @param  callable(): void|null  $beforeDeleting  runs once every check has passed — the
     *                                                 controller signs the user out here, which
     *                                                 must happen before the row is deleted
     */
    public function handle(User $user, ?callable $beforeDeleting = null): void
    {
        if ($this->history->hasHistory($user)) {
            throw ValidationException::withMessages([
                'password' => __('This account appears in business records, so it cannot be deleted. Ask an administrator to deactivate it instead.'),
            ]);
        }

        // Checked up front as well as by protect(), so the user is never signed out by a
        // deletion that is then refused.
        if ($user->isAdministrator() && $this->guard->activeAdministratorCount() <= 1) {
            throw ValidationException::withMessages([
                'password' => __('You are the only active administrator, so this account cannot be deleted. Make another user an administrator first.'),
            ]);
        }

        $this->guard->protect(function () use ($user, $beforeDeleting): void {
            $user->roles()->detach();

            if ($beforeDeleting !== null) {
                $beforeDeleting();
            }

            $user->delete();
        }, 'password');
    }
}
