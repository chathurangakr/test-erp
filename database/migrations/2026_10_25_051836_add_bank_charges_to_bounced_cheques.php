<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank charges on a bounced cheque (2026-10-25): what the bank took for the bounce is recorded
 * with it — `Dr bank charges / Cr the cheque's bank account` — and undone with it. The expense
 * account is `accounting_settings.bank_charges_account_id` (6970). See docs/ACCOUNTING_RULES.md §35.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('bank_charges_account_id')->nullable()->after('sales_discount_account_id')
                ->constrained('accounts')->restrictOnDelete();
        });

        Schema::table('cheque_events', function (Blueprint $table) {
            $table->decimal('bank_charge', 20, 6)->nullable()->after('reason');
            $table->foreignId('journal_entry_id')->nullable()->after('bank_charge')->constrained('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cheque_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn('bank_charge');
        });

        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_charges_account_id');
        });
    }
};
