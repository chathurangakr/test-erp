<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox of transaction alerts (2026-10-25). A row is written in the SAME database
 * transaction as the posting or reversal it reports, so an alert exists exactly when the
 * document does; `telegram:send-alerts` (every minute) sends it afterwards, so Telegram being
 * slow or down never holds up or breaks a posting. The message is written when it is sent, from
 * the document itself; `context` keeps only what the event erased (an undone asset sale).
 * See docs/TELEGRAM.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_alerts', function (Blueprint $table) {
            $table->id();
            // The kind of document (TransactionAlertCatalog), and which one.
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            // posted, reversed, sold, sale_reversed.
            $table->string('event', 20);
            $table->json('context')->nullable();
            // PENDING → SENT, or FAILED after repeated refusals.
            $table->string('status', 10)->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            // Chats already sent to, so a retry never repeats a message.
            $table->json('delivered_to')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_alerts');
    }
};
