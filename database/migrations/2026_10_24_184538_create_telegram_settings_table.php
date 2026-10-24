<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Telegram bot loan reminders are also sent through (2026-10-25). One row, edited on
 * Administration → Telegram settings. The bot token is encrypted with APP_KEY and never sent back
 * to a page; the chats are the people or groups chosen to receive reminders. See docs/LOANS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->text('bot_token')->nullable();
            $table->string('bot_username', 100)->nullable();
            // [{id, title}] — the private chats and groups reminders go to.
            $table->json('chats')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_settings');
    }
};
