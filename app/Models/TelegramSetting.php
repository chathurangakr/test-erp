<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row naming the Telegram bot reminders go out through, edited on Administration →
 * Telegram settings. `bot_token` is encrypted with the application key and hidden from
 * serialisation: no screen ever receives it back.
 *
 * @property bool $is_enabled
 * @property string|null $bot_token
 * @property string|null $bot_username
 * @property list<array{id: string, title: string}>|null $chats
 */
class TelegramSetting extends Model
{
    protected $fillable = ['is_enabled', 'bot_token', 'bot_username', 'chats', 'updated_by'];

    protected $hidden = ['bot_token'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'bot_token' => 'encrypted',
            'chats' => 'array',
        ];
    }

    /** The saved settings, or unsaved empty ones. A read never writes. */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(['is_enabled' => false, 'chats' => []]);
    }

    /** Whether reminders should go out through Telegram. */
    public function isReady(): bool
    {
        return $this->is_enabled && $this->bot_token !== null && ($this->chats ?? []) !== [];
    }
}
