<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateTelegramSettingsRequest;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Telegram settings — the bot loan reminders also go out through, the chats that receive them,
 * and a test message. The saved token never reaches the screen: the page only learns whether one
 * is saved and the bot's @username. See docs/LOANS.md.
 */
class TelegramSettingsController extends Controller
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function edit(Request $request): Response
    {
        $settings = TelegramSetting::current();

        return Inertia::render('administration/telegram/Edit', [
            'settings' => [
                'is_enabled' => $settings->is_enabled,
                'has_token' => $settings->bot_token !== null,
                'bot_username' => $settings->bot_username,
                'chats' => $settings->chats ?? [],
            ],
            // Chats that have written to the bot, found with "Find chats" just now.
            'foundChats' => $request->session()->get('telegram_found_chats'),
        ]);
    }

    public function update(UpdateTelegramSettingsRequest $request): RedirectResponse
    {
        $settings = TelegramSetting::query()->orderBy('id')->first() ?? new TelegramSetting;
        $token = $request->validated('bot_token');

        if (filled($token)) {
            $settings->bot_token = $token;
            $settings->bot_username = $this->check(fn (): string => $this->bot->username($token), 'bot_token');
        }

        $settings->chats = array_values($request->validated('chats') ?? []);
        $settings->is_enabled = (bool) $request->validated('is_enabled', false);

        if ($settings->is_enabled && ($settings->bot_token === null || $settings->chats === [])) {
            throw ValidationException::withMessages(['is_enabled' => __('Save the bot token and choose at least one chat before switching Telegram on.')]);
        }

        $settings->updated_by = $request->user()->id;
        $settings->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Telegram settings saved.')]);

        return to_route('telegram-settings.edit');
    }

    /**
     * Lists the private chats and groups that have messaged the bot, so they can be chosen.
     */
    public function findChats(Request $request): RedirectResponse
    {
        $token = TelegramSetting::current()->bot_token
            ?? throw ValidationException::withMessages(['bot_token' => __('Save the bot token first.')]);

        $chats = $this->check(fn (): array => $this->bot->recentChats($token), 'find');

        if ($chats === []) {
            throw ValidationException::withMessages(['find' => __('Nobody has messaged the bot yet. Send it any message in Telegram, then try again.')]);
        }

        return back()->with('telegram_found_chats', $chats);
    }

    /**
     * Sends a short test message to every chosen chat.
     */
    public function test(): RedirectResponse
    {
        $settings = TelegramSetting::current();

        if ($settings->bot_token === null || ($settings->chats ?? []) === []) {
            throw ValidationException::withMessages(['test' => __('Save the bot token and choose at least one chat first.')]);
        }

        foreach ($settings->chats as $chat) {
            $this->check(fn () => $this->bot->send($settings->bot_token, $chat['id'], '✅ '.e(__(':app will send loan reminders here.', ['app' => config('app.name')]))), 'test');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test message sent to :count chat(s).', ['count' => count($settings->chats)])]);

        return back();
    }

    /**
     * Runs a Telegram call, turning its failure into a message beside the field.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function check(callable $call, string $field): mixed
    {
        try {
            return $call();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
