<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Mail\WeeklyUpdate;
use App\Models\User;
use App\Services\Reporting\WeeklyUpdateReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the weekly update (Monday–Sunday) to every active user holding
 * `reports.weekly_email` — administrators always do. Scheduled for Sunday 20:00 Sri Lanka time
 * in routes/console.php. `--to` sends one copy to a single address instead, as a test.
 * See docs/WEEKLY_UPDATE.md.
 */
class SendWeeklyUpdateCommand extends Command
{
    protected $signature = 'reports:weekly-update
        {--to= : Send one copy to this address only (a test)}';

    protected $description = 'Email the weekly update to everyone who receives it';

    public function handle(WeeklyUpdateReport $reports): int
    {
        [$from, $to] = WeeklyUpdateReport::lastWeek();
        $report = $reports->build($from, $to);

        $recipients = $this->option('to')
            ? collect([(string) $this->option('to')])
            : User::query()
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->hasPermission(Permission::ReportsWeeklyEmail));

        if ($recipients->isEmpty()) {
            $this->warn('Nobody receives the weekly update (no active user holds reports.weekly_email).');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($recipients as $recipient) {
            $address = $recipient instanceof User ? $recipient->email : $recipient;

            try {
                Mail::to($recipient)
                    ->locale($recipient instanceof User ? ($recipient->locale ?: config('app.locale')) : config('app.locale'))
                    ->send(new WeeklyUpdate($report));
                $this->info("Sent to {$address}");
            } catch (Throwable $exception) {
                // One bad address must not stop the others; the failure is reported and logged.
                $failed++;
                report($exception);
                $this->error("Could not send to {$address}: {$exception->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
