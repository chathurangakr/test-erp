<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two accounts payroll posts to: the wages expense salaries are charged to, and the
 * employee advances asset (a control account) advances sit in until salaries take them back.
 *
 * Configuration, never a code in posting logic. Set in Accounting Setup; a fresh install
 * seeds 6300 and 1220.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('wages_account_id')
                ->nullable()
                ->after('production_labour_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
            $table->foreignId('employee_advances_account_id')
                ->nullable()
                ->after('wages_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_advances_account_id');
            $table->dropConstrainedForeignId('wages_account_id');
        });
    }
};
