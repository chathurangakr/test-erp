<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Telegram settings. A blank bot token keeps the saved one; the token is never sent back.
 */
class UpdateTelegramSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['boolean'],
            // A BotFather token: digits, a colon and letters, digits, _ or -.
            'bot_token' => ['nullable', 'string', 'max:100', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
            'chats' => ['nullable', 'array', 'max:20'],
            'chats.*.id' => ['required', 'string', 'max:30', 'regex:/^-?\d+$/'],
            'chats.*.title' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['bot_token.regex' => __('That does not look like a bot token. Copy the whole token BotFather sent, like 123456:ABC-xyz.')];
    }
}
