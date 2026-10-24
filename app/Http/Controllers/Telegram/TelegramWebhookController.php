<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramCommandHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Where Telegram delivers what people send the transactions bot (a webhook). No session, no
 * cookies, no sign-in: the request is trusted only when it carries the secret this server gave
 * Telegram when commands were switched on (X-Telegram-Bot-Api-Secret-Token), compared in constant
 * time. Anything else is refused. Who may see what is then decided per person by
 * TelegramCommandHandler. See docs/TELEGRAM.md.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramCommandHandler $handler): JsonResponse
    {
        $settings = TelegramSetting::current(TelegramSetting::TRANSACTIONS);
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! $settings->answersCommands() || $secret === '' || ! hash_equals((string) $settings->webhook_secret, $secret)) {
            abort(403);
        }

        try {
            $handler->handle($settings, $request->json()->all());
        } catch (Throwable $exception) {
            // Telegram retries anything but a 200, which would repeat a failing message forever.
            report($exception);
        }

        return response()->json(['ok' => true]);
    }
}
