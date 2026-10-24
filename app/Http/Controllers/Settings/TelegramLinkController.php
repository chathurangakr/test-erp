<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramCommandHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Profile → Telegram: a person links their own Telegram account to the transactions
 * bot, or unlinks it. Linking makes a one-time code (TelegramCommandHandler::LINK_MINUTES) and
 * opens the bot with it; pressing Start there completes the link. Only the person's own account
 * changes, so this needs no permission. See docs/TELEGRAM.md.
 */
class TelegramLinkController extends Controller
{
    public function store(Request $request): Response
    {
        $settings = TelegramSetting::current(TelegramSetting::TRANSACTIONS);

        if (! $settings->answersCommands() || $settings->bot_username === null) {
            throw ValidationException::withMessages(['telegram' => __('Telegram commands are not switched on. Ask an administrator.')]);
        }

        $code = Str::random(32);
        Cache::put(TelegramCommandHandler::linkKey($code), $request->user()->id, now()->addMinutes(TelegramCommandHandler::LINK_MINUTES));

        return Inertia::location('https://t.me/'.$settings->bot_username.'?start='.$code);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['telegram_user_id' => null, 'telegram_username' => null, 'telegram_linked_at' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Telegram unlinked.')]);

        return to_route('profile.edit');
    }
}
