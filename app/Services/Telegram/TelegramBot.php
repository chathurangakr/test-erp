<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The three calls the ERP makes to the Telegram Bot API: who the bot is, which chats have
 * written to it (so they can be chosen), and sending a message. Nothing is received by webhook;
 * the server only ever calls out. See https://core.telegram.org/bots/api.
 */
class TelegramBot
{
    private const API = 'https://api.telegram.org/bot';

    /**
     * The bot's @username, which also proves the token works.
     */
    public function username(string $token): string
    {
        return (string) $this->call($token, 'getMe')['username'];
    }

    /**
     * The private chats and groups that have messaged the bot recently, newest first.
     *
     * @return list<array{id: string, title: string}>
     */
    public function recentChats(string $token): array
    {
        $chats = [];

        foreach (array_reverse($this->call($token, 'getUpdates')) as $update) {
            $chat = ($update['message'] ?? $update['my_chat_member'] ?? $update['channel_post'] ?? [])['chat'] ?? null;

            if ($chat === null || isset($chats[(string) $chat['id']])) {
                continue;
            }

            $title = $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''));
            $chats[(string) $chat['id']] = [
                'id' => (string) $chat['id'],
                'title' => $title !== '' ? $title : ('@'.($chat['username'] ?? $chat['id'])),
            ];
        }

        return array_values($chats);
    }

    /**
     * Sends an HTML-formatted message to one chat, optionally with buttons under it.
     *
     * @param  list<list<array{text: string, callback_data: string}>>  $buttons  rows of buttons
     */
    public function send(string $token, string $chatId, string $html, array $buttons = []): void
    {
        $this->call($token, 'sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $buttons === [] ? null : ['inline_keyboard' => $buttons],
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Has Telegram send this bot's messages to $url, signed with $secret, from now on.
     */
    public function setWebhook(string $token, string $url, string $secret): void
    {
        $this->call($token, 'setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    /** Stops Telegram sending this bot's messages here. */
    public function deleteWebhook(string $token): void
    {
        $this->call($token, 'deleteWebhook', ['drop_pending_updates' => true]);
    }

    /**
     * The command list Telegram shows when someone types "/", optionally for one language.
     *
     * @param  array<string, string>  $commands  command (without "/") => description
     */
    public function setCommands(string $token, array $commands, ?string $language = null): void
    {
        $this->call($token, 'setMyCommands', array_filter([
            'commands' => array_map(fn (string $command, string $description): array => ['command' => $command, 'description' => $description], array_keys($commands), $commands),
            'language_code' => $language,
        ], fn ($value): bool => $value !== null));
    }

    /** Stops the spinner on a pressed button. */
    public function answerButton(string $token, string $callbackId): void
    {
        $this->call($token, 'answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    private function call(string $token, string $method, array $payload = []): array
    {
        try {
            $response = Http::timeout(15)->asJson()->post(self::API.$token.'/'.$method, $payload)->throw();
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 429) {
                // Flood control: nothing is wrong, the message just has to wait.
                throw new TelegramUnreachable(__('Telegram asked to slow down. Try again in :seconds seconds.', [
                    'seconds' => (int) ($exception->response->json('parameters.retry_after') ?? 30),
                ]));
            }

            // Never repeat the token (it is in the URL) in a message that reaches a screen or log,
            // so the original exception is not chained.
            throw new RuntimeException(__('Telegram refused the request: :reason', [
                'reason' => $exception->response?->json('description') ?? __('Telegram did not answer.'),
            ]));
        } catch (ConnectionException) {
            throw new TelegramUnreachable(__('Telegram could not be reached. Check the server\'s internet connection.'));
        }

        return (array) $response->json('result');
    }
}
