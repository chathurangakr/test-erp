<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Year-end closing (2026-10-25). The financial year ends on the last day of
 * `accounting_settings.financial_year_end_month` — March (1 April to 31 March), as the owner
 * confirmed. Closing a year writes one journal, dated the year's last day, that empties every
 * revenue, cost-of-sales and expense account into retained earnings; this table records each
 * close and its reversal. See docs/ACCOUNTING_RULES.md §24.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('financial_year_end_month')->default(3)->after('operational_accounting_start_date');
        });

        DB::table('accounting_settings')->update(['financial_year_end_month' => 3]);

        Schema::create('financial_year_closings', function (Blueprint $table) {
            $table->id();
            $table->date('year_start_date');
            $table->date('year_end_date');
            // Profit (or, negative, loss) moved into retained earnings — the journal's own figure.
            $table->decimal('net_profit', 20, 6);
            $table->foreignId('retained_earnings_account_id')->constrained('accounts')->restrictOnDelete();
            // NULL when the year had nothing to close.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('status', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['year_end_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_year_closings');

        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropColumn('financial_year_end_month');
        });
    }
};
