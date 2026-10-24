<?php

namespace App\Console\Commands;

use App\Models\TelegramAlert;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TelegramUnreachable;
use App\Services\Telegram\TransactionAlertMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Sends the waiting transaction alerts to the transactions bot's chats, oldest first. Scheduled
 * every minute in routes/console.php.
 *
 * At most BATCH alerts go per run, PAUSE_MS apart, inside Telegram's limits (about one message a
 * second to a chat, 20 a minute to a group); the rest wait for the next minute. A chat already sent to is never sent again, so a run
 * cut short repeats nothing. When Telegram cannot be reached the run stops and the alerts wait;
 * when it refuses one, that alert is retried and given up after TelegramAlert::MAX_ATTEMPTS.
 * While the bot is switched off, waiting alerts stay waiting. See docs/TELEGRAM.md.
 */
#[Signature('telegram:send-alerts')]
#[Description('Send the waiting transaction alerts to the Telegram transactions bot')]
class SendTelegramAlertsCommand extends Command
{
    public const BATCH = 15;

    /** The pause between two messages, under Telegram's limit of about one a second per chat. */
    public const PAUSE_MS = 1100;

    public function handle(TelegramBot $bot, TransactionAlertMessage $messages): int
    {
        $settings = TelegramSetting::current(TelegramSetting::TRANSACTIONS);

        if (! $settings->isReady()) {
            return self::SUCCESS;
        }

        $sentThisRun = 0;
        $alerts = TelegramAlert::query()->where('status', TelegramAlert::PENDING)->orderBy('id')->limit(self::BATCH)->get();

        foreach ($alerts as $alert) {
            try {
                $text = $messages->forAlert($alert);
            } catch (Throwable $exception) {
                // A message that cannot be written must not hold up every alert behind it.
                report($exception);
                $alert->update(['status' => TelegramAlert::FAILED, 'last_error' => mb_substr($exception->getMessage(), 0, 500)]);

                continue;
            }

            if ($text === null) {
                $alert->update(['status' => TelegramAlert::FAILED, 'last_error' => 'The document no longer exists.']);

                continue;
            }

            $delivered = $alert->delivered_to ?? [];

            try {
                foreach ($settings->chats as $chat) {
                    if (in_array($chat['id'], $delivered, true)) {
                        continue;
                    }

                    // Telegram allows about one message a second to a chat; stay under it.
                    if ($sentThisRun++ > 0) {
                        Sleep::for(self::PAUSE_MS)->milliseconds();
                    }

                    $bot->send((string) $settings->bot_token, $chat['id'], $text);
                    $delivered[] = $chat['id'];
                }
            } catch (RuntimeException $exception) {
                $unreachable = $exception instanceof TelegramUnreachable;
                $attempts = $alert->attempts + ($unreachable ? 0 : 1);

                $alert->update([
                    'delivered_to' => $delivered,
                    'attempts' => $attempts,
                    'status' => $attempts >= TelegramAlert::MAX_ATTEMPTS ? TelegramAlert::FAILED : TelegramAlert::PENDING,
                    'last_error' => mb_substr($exception->getMessage(), 0, 500),
                ]);
                $this->error("Alert {$alert->id}: {$exception->getMessage()}");

                if ($unreachable) {
                    // Telegram is down, the internet is, or Telegram asked to slow down: try
                    // everything again next minute.
                    return self::FAILURE;
                }

                continue;
            }

            $alert->update(['status' => TelegramAlert::SENT, 'delivered_to' => $delivered, 'sent_at' => now(), 'last_error' => null]);
        }

        return self::SUCCESS;
    }
}
