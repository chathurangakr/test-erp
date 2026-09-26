<?php

namespace App\Actions\Api;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\NewAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Signs a user in from a device and issues a bearer token.
 *
 * Applies the same gates as the web sign-in: the password must match, the account must be
 * active, and an account with two-factor authentication must supply a valid code or recovery
 * code — the API is not a way around 2FA.
 *
 * The token is Sanctum's: stored hashed, returned in plain text exactly once, carrying only
 * the `mobile-api` ability and an expiry from config('api.token_lifetime_days'). What the user
 * may do is still decided by their roles on every request. One token per sign-in, so each
 * device can be revoked on its own.
 */
class IssueApiToken
{
    public function __construct(private readonly TwoFactorAuthenticationProvider $twoFactor) {}

    /**
     * @throws ValidationException for bad credentials or a missing/invalid 2FA code
     * @throws HttpException (403) for a deactivated account
     */
    public function handle(
        string $email,
        string $password,
        string $deviceName,
        ?string $code = null,
        ?string $recoveryCode = null,
    ): NewAccessToken {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        // Only revealed once the password is proven, so it tells a guesser nothing.
        if (! $user->is_active) {
            throw new HttpException(403, __('This account has been deactivated. Please contact an administrator.'));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->assertSecondFactor($user, $code, $recoveryCode);
        }

        return $user->createToken(
            $deviceName,
            [config('api.token_ability')],
            now()->addDays((int) config('api.token_lifetime_days')),
        );
    }

    private function assertSecondFactor(User $user, ?string $code, ?string $recoveryCode): void
    {
        if ($code !== null && $code !== '') {
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

            if ($this->twoFactor->verify($secret, $code)) {
                return;
            }

            throw ValidationException::withMessages(['code' => __('The two-factor authentication code is invalid.')]);
        }

        if ($recoveryCode !== null && $recoveryCode !== '') {
            $match = collect($user->recoveryCodes())->first(fn (string $stored): bool => hash_equals($stored, $recoveryCode));

            if ($match !== null) {
                // A recovery code works once, exactly as on the web.
                $user->replaceRecoveryCode($match);

                return;
            }

            throw ValidationException::withMessages(['recovery_code' => __('The recovery code is invalid.')]);
        }

        throw ValidationException::withMessages([
            'code' => __('This account uses two-factor authentication. Provide the code from your authenticator app, or a recovery code.'),
        ]);
    }
}
