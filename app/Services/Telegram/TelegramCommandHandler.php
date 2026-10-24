<?php

namespace App\Services\Telegram;

use App\Models\TelegramSetting;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Answers what people send the transactions bot. Read-only: nothing here posts or changes a
 * business record.
 *
 * - **Only linked people get answers.** A person links their Telegram account once from Settings
 *   → Profile (a one-time code, 15 minutes, carried by Telegram's /start link). Everyone else is
 *   told how to link, and learns nothing.
 * - **Each answer follows the person's ERP permissions**, checked again on every message and
 *   button, so a deactivated user or a removed role stops answers at once (TelegramAnswers).
 * - **Private chats only.** In a group the bot asks to be messaged privately, so figures never
 *   reach people who could not see them in the ERP.
 * - Replies are in the person's ERP language.
 *
 * See docs/TELEGRAM.md.
 */
class TelegramCommandHandler
{
    /** How long a "Link my Telegram" code is good for. */
    public const LINK_MINUTES = 15;

    private const SEEN_CHATS = 20;

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly TelegramAnswers $answers,
    ) {}

    /** The cache key holding the user a link code belongs to. */
    public static function linkKey(string $code): string
    {
        return 'telegram-link:'.$code;
    }

    /**
     * @param  array<string, mixed>  $update  one update as Telegram sent it
     */
    public function handle(TelegramSetting $settings, array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->button($settings, $update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;

        if (! is_array($message) || ! isset($message['chat']['id'], $message['from']['id'])) {
            return;
        }

        $this->remember($settings, $message['chat']);
        $text = trim((string) ($message['text'] ?? ''));

        if (! str_starts_with($text, '/')) {
            return;
        }

        // "/stock@SomeBot extra" → "stock", "extra".
        [$word, $argument] = array_pad(explode(' ', $text, 2), 2, '');
        $command = strtolower(explode('@', substr($word, 1))[0]);
        $chatId = (string) $message['chat']['id'];

        if (($message['chat']['type'] ?? '') !== 'private') {
            $this->reply($settings, $chatId, ['text' => '🔒 '.e(__('For privacy I only answer in a private chat. Tap my name and send me the command there.')), 'buttons' => []]);

            return;
        }

        if ($command === 'start' && trim($argument) !== '') {
            $this->link($settings, $chatId, $message['from'], trim($argument));

            return;
        }

        $user = $this->linkedUser((string) $message['from']['id']);

        if ($user === null) {
            $this->reply($settings, $chatId, ['text' => '👋 '.e(__('I don\'t know you yet. In the ERP open Settings → Profile and click "Link my Telegram".')), 'buttons' => []]);

            return;
        }

        App::setLocale($user->locale ?: config('app.locale'));

        $answer = match (true) {
            ! array_key_exists($command, TelegramAnswers::COMMANDS) => $this->answers->help($user),
            ! TelegramAnswers::allows($user, $command) => $this->answers->notAllowed(),
            default => $this->answers->{$command}($user),
        };

        $this->reply($settings, $chatId, $answer);
    }

    /**
     * A pressed button under an answer — "stock:<product id>".
     *
     * @param  array<string, mixed>  $query
     */
    private function button(TelegramSetting $settings, array $query): void
    {
        $this->bot->answerButton((string) $settings->bot_token, (string) $query['id']);

        $chatId = (string) ($query['message']['chat']['id'] ?? '');
        $user = $this->linkedUser((string) ($query['from']['id'] ?? ''));

        if ($chatId === '' || $user === null || ($query['message']['chat']['type'] ?? '') !== 'private') {
            return;
        }

        App::setLocale($user->locale ?: config('app.locale'));

        if (preg_match('/^stock:(\d+)$/', (string) ($query['data'] ?? ''), $match)) {
            $this->reply($settings, $chatId, TelegramAnswers::allows($user, 'stock')
                ? $this->answers->stockItem($user, (int) $match[1])
                : $this->answers->notAllowed());
        }
    }

    /**
     * Links the Telegram account that opened a "Link my Telegram" link to the ERP user it was
     * made for. A code works once; a Telegram account belongs to one ERP user.
     *
     * @param  array<string, mixed>  $from
     */
    private function link(TelegramSetting $settings, string $chatId, array $from, string $code): void
    {
        $userId = preg_match('/^[A-Za-z0-9]{20,64}$/', $code) ? Cache::pull(self::linkKey($code)) : null;
        $user = $userId === null ? null : User::query()->where('is_active', true)->find($userId);

        if ($user === null) {
            $this->reply($settings, $chatId, ['text' => '⚠️ '.e(__('That link has expired or was already used. In the ERP click "Link my Telegram" again.')), 'buttons' => []]);

            return;
        }

        $telegramId = (string) $from['id'];

        DB::transaction(function () use ($user, $telegramId, $from): void {
            // One Telegram account, one ERP user: a re-link moves it.
            User::query()->where('telegram_user_id', $telegramId)->whereKeyNot($user->id)
                ->update(['telegram_user_id' => null, 'telegram_username' => null, 'telegram_linked_at' => null]);

            $user->forceFill([
                'telegram_user_id' => $telegramId,
                'telegram_username' => isset($from['username']) ? mb_substr((string) $from['username'], 0, 100) : null,
                'telegram_linked_at' => now(),
            ])->save();
        });

        App::setLocale($user->locale ?: config('app.locale'));
        $welcome = $this->answers->help($user);
        $welcome['text'] = '✅ '.e(__('Linked to your ERP account (:email).', ['email' => $user->email]))."\n\n".$welcome['text'];

        $this->reply($settings, $chatId, $welcome);
    }

    private function linkedUser(string $telegramId): ?User
    {
        return $telegramId === '' ? null : User::query()->where('telegram_user_id', $telegramId)->where('is_active', true)->first();
    }

    /**
     * Keeps the chats that wrote recently, for "Find chats" (Telegram's own list is unavailable
     * while it sends updates here).
     *
     * @param  array<string, mixed>  $chat
     */
    private function remember(TelegramSetting $settings, array $chat): void
    {
        $id = (string) $chat['id'];
        $title = $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''));
        $seen = array_values(array_filter($settings->seen_chats ?? [], fn (array $known): bool => $known['id'] !== $id));
        array_unshift($seen, ['id' => $id, 'title' => mb_substr($title !== '' ? $title : '@'.($chat['username'] ?? $id), 0, 150)]);

        $settings->forceFill(['seen_chats' => array_slice($seen, 0, self::SEEN_CHATS)])->save();
    }

    /**
     * @param  array{text: string, buttons: list<list<array{text: string, callback_data: string}>>}  $answer
     */
    private function reply(TelegramSetting $settings, string $chatId, array $answer): void
    {
        $this->bot->send((string) $settings->bot_token, $chatId, $answer['text'], $answer['buttons']);
    }
}
