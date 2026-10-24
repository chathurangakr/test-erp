<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Mail\MonthlyUpdate;
use App\Models\CompanyProfile;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Reporting\MonthlyUpdateReport;
use App\Services\Telegram\MonthlyUpdateMessage;
use App\Services\Telegram\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * The monthly report for last month, on the 1st at 08:00 Sri Lanka time (routes/console.php):
 * emailed to every active user holding `reports.monthly_email` (administrators always do), and
 * sent to the chats of every Telegram bot with "send the monthly report here" ticked.
 * `--to` sends one email to that address only (a test, no Telegram); `--month=YYYY-MM` reports
 * another month. One failing address or chat does not stop the others. See docs/WEEKLY_UPDATE.md.
 */
class SendMonthlyUpdateCommand extends Command
{
    protected $signature = 'reports:monthly-update
        {--to= : Send one email to this address only (a test; nothing goes to Telegram)}
        {--month= : The month to report, YYYY-MM (default: last month)}';

    protected $description = 'Email and Telegram the monthly report to everyone who receives it';

    public function handle(MonthlyUpdateReport $reports, TelegramBot $bot): int
    {
        $month = (string) $this->option('month');

        if ($month !== '' && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('--month must look like 2026-10.');

            return self::INVALID;
        }

        [$from, $to] = $month === '' ? MonthlyUpdateReport::lastMonth() : MonthlyUpdateReport::month($month);
        $report = $reports->build($from, $to);
        $failed = 0;

        $recipients = $this->option('to')
            ? collect([(string) $this->option('to')])
            : User::query()
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->hasPermission(Permission::ReportsMonthlyEmail));

        foreach ($recipients as $recipient) {
            $address = $recipient instanceof User ? $recipient->email : $recipient;

            try {
                Mail::to($recipient)
                    ->locale($recipient instanceof User ? ($recipient->locale ?: config('app.locale')) : config('app.locale'))
                    ->send(new MonthlyUpdate($report));
                $this->info("Emailed {$address}");
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error("Could not email {$address}: {$exception->getMessage()}");
            }
        }

        if (! $this->option('to')) {
            $failed += $this->sendToTelegram($report, $bot);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return int the chats that could not be sent to
     */
    private function sendToTelegram(array $report, TelegramBot $bot): int
    {
        $messages = MonthlyUpdateMessage::forTelegram($report, CompanyProfile::current()->name);
        $failed = 0;

        foreach (TelegramSetting::query()->where('sends_monthly_report', true)->get() as $settings) {
            if (! $settings->isReady()) {
                continue;
            }

            foreach ($settings->chats as $chat) {
                try {
                    foreach ($messages as $text) {
                        Sleep::for(1100)->milliseconds();
                        $bot->send((string) $settings->bot_token, $chat['id'], $text);
                    }
                    $this->info("Sent to Telegram chat {$chat['title']}");
                } catch (RuntimeException $exception) {
                    $failed++;
                    $this->error("Could not send to Telegram chat {$chat['title']}: {$exception->getMessage()}");
                }
            }
        }

        return $failed;
    }
}
