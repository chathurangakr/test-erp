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
 * Loan installment reminders, every morning at 8 AM Sri Lanka time: 7 days before, 2 days before
 * and on the due day, to everyone holding loans.reminders. See docs/LOANS.md.
 */
Schedule::command('loans:remind')
    ->dailyAt('08:00')
    ->timezone(BusinessClock::timezone());
