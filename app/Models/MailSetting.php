<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single row naming the outgoing email account, edited on Administration → Email settings.
 *
 * `password` is encrypted with the application key and hidden from serialisation: no screen ever
 * receives it back. While `is_enabled` is false, the .env mail settings apply unchanged.
 *
 * @property bool $is_enabled
 * @property string $host
 * @property int $port
 * @property string $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_address
 * @property string|null $from_name
 */
class MailSetting extends Model
{
    /** The choices offered on screen: STARTTLS on 587 (Gmail's default) or SSL on 465. */
    public const ENCRYPTIONS = ['tls', 'ssl'];

    protected $fillable = [
        'is_enabled',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'from_address',
        'from_name',
        'updated_by',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'port' => 'integer',
            'password' => 'encrypted',
        ];
    }

    /**
     * The saved settings, or unsaved Gmail defaults while none are saved. A read never writes.
     */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first()
            ?? new self(['is_enabled' => false, 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls']);
    }

    /**
     * Points Laravel's mailer at this account, when switched on and complete.
     */
    public function applyToConfig(): void
    {
        if (! $this->is_enabled || $this->username === null || $this->password === null) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $this->encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => $this->host,
            'mail.mailers.smtp.port' => $this->port,
            'mail.mailers.smtp.username' => $this->username,
            'mail.mailers.smtp.password' => $this->password,
            'mail.from.address' => $this->from_address ?: $this->username,
            'mail.from.name' => $this->from_name ?: config('app.name'),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
