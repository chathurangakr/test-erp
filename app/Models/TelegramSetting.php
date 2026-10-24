<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Telegram bot, edited on Administration → Telegram settings. There are two, named by
 * `purpose`: the reminders bot (loan reminders) and the transactions bot (a message for every
 * posting and reversal — App\Services\Telegram\TransactionAlertCatalog). `bot_token` is
 * encrypted with the application key and hidden from serialisation: no screen ever receives it
 * back. See docs/TELEGRAM.md.
 *
 * @property string $purpose
 * @property bool $is_enabled
 * @property string|null $bot_token
 * @property string|null $bot_username
 * @property list<array{id: string, title: string}>|null $chats
 * @property list<string>|null $alert_types
 * @property bool $sends_monthly_report
 * @property bool $commands_enabled whether Telegram sends this bot's messages here (commands)
 * @property string|null $webhook_secret
 * @property list<array{id: string, title: string}>|null $seen_chats
 */
class TelegramSetting extends Model
{
    public const REMINDERS = 'reminders';

    public const TRANSACTIONS = 'transactions';

    public const PURPOSES = [self::REMINDERS, self::TRANSACTIONS];

    protected $fillable = ['purpose', 'is_enabled', 'bot_token', 'bot_username', 'chats', 'alert_types', 'sends_monthly_report', 'commands_enabled', 'webhook_secret', 'seen_chats', 'updated_by'];

    protected $hidden = ['bot_token', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'bot_token' => 'encrypted',
            'chats' => 'array',
            'alert_types' => 'array',
            'sends_monthly_report' => 'boolean',
            'commands_enabled' => 'boolean',
            'webhook_secret' => 'encrypted',
            'seen_chats' => 'array',
        ];
    }

    /** One bot's saved settings, or unsaved empty ones. A read never writes. */
    public static function current(string $purpose = self::REMINDERS): self
    {
        return self::query()->where('purpose', $purpose)->first()
            ?? new self(['purpose' => $purpose, 'is_enabled' => false, 'chats' => [], 'sends_monthly_report' => $purpose === self::REMINDERS]);
    }

    /** Whether messages should go out through this bot. */
    public function isReady(): bool
    {
        return $this->is_enabled && $this->bot_token !== null && ($this->chats ?? []) !== [];
    }

    /** Whether this bot answers commands: it listens, and has a token to answer with. */
    public function answersCommands(): bool
    {
        return $this->commands_enabled && $this->bot_token !== null && $this->webhook_secret !== null;
    }

    /** Whether the transactions bot reports this kind of document (no choice saved = all). */
    public function wantsAlert(string $type): bool
    {
        return $this->isReady() && ($this->alert_types === null || in_array($type, $this->alert_types, true));
    }
}
