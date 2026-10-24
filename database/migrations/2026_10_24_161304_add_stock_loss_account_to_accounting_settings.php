<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The expense damaged stock is written off to.
 *
 * Configuration, never a code in posting logic. Set in Accounting Setup; a fresh install seeds
 * 6900 Stock Damage and Losses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('stock_loss_account_id')
                ->nullable()
                ->after('employee_advances_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_loss_account_id');
        });
    }
};
