<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account credited when production labour is absorbed into inventory.
 *
 * Dehusking labour becomes raw-husk cost: Dr Raw Husk Inventory / Cr this account. It is a
 * contra-expense — wages are still paid by expense voucher to the wages account — so no cash
 * and no payable is ever created by production. Configuration, never a code in posting logic.
 *
 * See docs/ACCOUNTING_RULES.md §30.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('production_labour_account_id')
                ->nullable()
                ->after('trade_receivables_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_labour_account_id');
        });
    }
};
