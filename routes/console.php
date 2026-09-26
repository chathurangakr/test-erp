<?php

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
