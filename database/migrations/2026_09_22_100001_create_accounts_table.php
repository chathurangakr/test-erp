<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts.
 *
 * There is deliberately NO balance column. An account's balance is always derived from
 * posted journal lines, exactly as stock is always derived from the inventory ledger: a
 * stored balance has no history, and when it drifts there is no way to find out when or why.
 *
 * `restrictOnDelete` on the self-reference, and no destroy route anywhere, because an
 * account with history must keep existing for that history to mean anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 255);
            $table->string('account_type', 30)->index();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('normal_balance', 10);

            // A control account is reconciled from a subsidiary ledger (receivables,
            // payables, inventory), so people must not post to it by hand — only the
            // system action that owns that subsidiary ledger may.
            $table->boolean('is_control_account')->default(false);
            $table->boolean('allow_manual_posting')->default(true);

            $table->boolean('is_active')->default(true)->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
