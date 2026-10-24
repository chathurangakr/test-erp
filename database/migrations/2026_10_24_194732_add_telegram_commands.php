<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram commands (2026-10-25): people type /stock, /cash, /today or /owed to the transactions
 * bot and it answers with what their ERP permissions allow.
 *
 * - users: the Telegram account each person linked from Settings → Profile (one person, one
 *   Telegram account). Unlinked people get no answers.
 * - telegram_settings: whether the bot listens (a webhook to this server), the secret Telegram
 *   sends with each update (encrypted with APP_KEY), and the chats seen recently — "Find chats"
 *   cannot ask Telegram once it sends updates here. See docs/TELEGRAM.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_user_id', 30)->nullable()->unique()->after('locale');
            $table->string('telegram_username', 100)->nullable()->after('telegram_user_id');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_username');
        });

        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->boolean('commands_enabled')->default(false)->after('sends_monthly_report');
            $table->text('webhook_secret')->nullable()->after('commands_enabled');
            $table->json('seen_chats')->nullable()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropColumn(['commands_enabled', 'webhook_secret', 'seen_chats']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_user_id']);
            $table->dropColumn(['telegram_user_id', 'telegram_username', 'telegram_linked_at']);
        });
    }
};
