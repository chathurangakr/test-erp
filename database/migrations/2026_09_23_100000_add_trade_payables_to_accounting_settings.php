<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Trade Payables control account, held as configuration.
 *
 * Accounts Payable actions ask the settings for it, so no account code appears in AP
 * business logic — the same rule the product mappings already follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('trade_payables_account_id')->nullable()->after('grni_account_id')
                ->constrained('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropForeign(['trade_payables_account_id']);
            $table->dropColumn('trade_payables_account_id');
        });
    }
};
