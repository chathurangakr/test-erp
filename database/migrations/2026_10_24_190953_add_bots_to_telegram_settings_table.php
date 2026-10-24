<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A second Telegram bot for transaction alerts (2026-10-25). Each row of telegram_settings is
 * now one bot, named by `purpose`: `reminders` (the existing row — loan reminders) or
 * `transactions` (a message for every posting and reversal). `alert_types` chooses which
 * documents the transactions bot reports (NULL = all); `sends_monthly_report` says whether the
 * monthly report on the 1st goes to that bot's chats too. See docs/TELEGRAM.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->string('purpose', 20)->default('reminders')->after('id')->unique();
            $table->json('alert_types')->nullable()->after('chats');
            $table->boolean('sends_monthly_report')->default(false)->after('alert_types');
        });

        // The reminders bot already set up gets the monthly report too.
        DB::table('telegram_settings')->where('purpose', 'reminders')->update(['sends_monthly_report' => true]);
    }

    public function down(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropUnique(['purpose']);
            $table->dropColumn(['purpose', 'alert_types', 'sends_monthly_report']);
        });
    }
};
