<?php

use App\Support\BusinessClock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Expired mobile tokens already stop working at their expires_at; this only removes the dead
 * rows. Requires the Laravel scheduler (`php artisan schedule:run` every minute via cron) on
 * the server. See docs/API_V1.md §3.
 */
Schedule::command('sanctum:prune-expired --hours=24')->daily();

/*
 * The weekly update email, Sunday 8 PM Sri Lanka time, to everyone holding
 * reports.weekly_email. Needs mail settings in .env (deploy/set-mail.sh). See docs/WEEKLY_UPDATE.md.
 */
Schedule::command('reports:weekly-update')
    ->weeklyOn(0, '20:00')
    ->timezone(BusinessClock::timezone());

/*
 * This month and next month always exist as open fiscal periods, so postings never stop at a
 * month end. Continues the accountant's calendar only; never changes or closes a period.
 */
Schedule::command('finance:open-next-periods')
    ->dailyAt('00:05')
    ->timezone(BusinessClock::timezone());

/*
 * The monthly report for last month, on the 1st at 8 AM Sri Lanka time: by email to everyone
 * holding reports.monthly_email, and to the Telegram bots set to receive it. See docs/WEEKLY_UPDATE.md.
 */
Schedule::command('reports:monthly-update')
    ->monthlyOn(1, '08:00')
    ->timezone(BusinessClock::timezone());

/*
 * Loan installment reminders, every morning at 8 AM Sri Lanka time: 7 days before, 2 days before
 * and on the due day, to everyone holding loans.reminders. See docs/LOANS.md.
 */
Schedule::command('loans:remind')
    ->dailyAt('08:00')
    ->timezone(BusinessClock::timezone());

/*
 * Transaction alerts waiting for the Telegram transactions bot, sent every minute — never from
 * the posting itself, so Telegram can never slow down or break one. See docs/TELEGRAM.md.
 */
Schedule::command('telegram:send-alerts')
    ->everyMinute()
    ->withoutOverlapping();
