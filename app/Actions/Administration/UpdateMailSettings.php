<?php

namespace App\Actions\Administration;

use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves the outgoing email account — creating the single row the first time.
 *
 * A blank password keeps the saved one, so the screen never needs to hold it; spaces are removed
 * because Gmail shows app passwords in groups of four.
 */
class UpdateMailSettings
{
    /**
     * @param  array{is_enabled: bool, host: string, port: int, encryption: string, username: ?string, password?: ?string, from_address: ?string, from_name: ?string}  $settings
     */
    public function handle(array $settings, User $user): MailSetting
    {
        return DB::transaction(function () use ($settings, $user): MailSetting {
            $row = MailSetting::query()->orderBy('id')->lockForUpdate()->first() ?? new MailSetting;
            $password = str_replace(' ', '', (string) ($settings['password'] ?? ''));
            unset($settings['password']);

            $row->fill($settings);

            if ($password !== '') {
                $row->password = $password;
            }

            $row->updated_by = $user->id;
            $row->save();

            return $row;
        });
    }
}
