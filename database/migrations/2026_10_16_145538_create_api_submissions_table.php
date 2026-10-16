<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline entries sent by the mobile app — one row per entry that became a posted document.
 *
 * The phone gives every entry a UUID (its idempotency key) when it is recorded and sends that
 * key with every attempt. The UNIQUE index is what makes a retried sync harmless: the row is
 * written in the same database transaction as the document, so a key exists exactly when its
 * document does, and a second copy of the entry can never create a second document.
 *
 * It is also the audit trail of where an entry came from: who, which device, when it was
 * recorded on the phone and when it reached the ERP. Rows are never updated or deleted.
 * The token id has no foreign key because tokens are deleted on sign-out; the device name is
 * copied so it survives that. See docs/API_V1.md §17.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('operation', 50);
            $table->char('request_hash', 64);
            $table->morphs('document');
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_submissions');
    }
};
