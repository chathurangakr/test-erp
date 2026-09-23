<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money moved between the factory's own cash and bank accounts.
 *
 *   Dr destination cash / bank
 *       Cr source cash / bank
 *
 * A balance-sheet event only: no expense, no revenue, no payable, no receivable, and no
 * inventory. The company's combined cash-and-bank total is identical before and after —
 * which is exactly why it must never be recorded as anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('transfer_number', 50)->unique();
            $table->date('transfer_date');
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();

            $table->foreignId('from_cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->foreignId('to_cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();

            $table->decimal('amount', 20, 6);
            $table->string('reference', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('status', 30)->default('DRAFT');

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'transfer_date'], 'ft_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transfers');
    }
};
