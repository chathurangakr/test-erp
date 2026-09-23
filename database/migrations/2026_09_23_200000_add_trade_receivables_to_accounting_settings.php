<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Trade Receivables control account, held as configuration.
 *
 * Accounts receivable asks the settings for it, so no account code appears in AR business
 * logic — the same rule payables and the product mappings already follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('trade_receivables_account_id')->nullable()->after('trade_payables_account_id')
                ->constrained('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropForeign(['trade_receivables_account_id']);
            $table->dropColumn('trade_receivables_account_id');
        });
    }
};
