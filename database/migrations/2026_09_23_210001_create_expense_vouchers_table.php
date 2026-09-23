<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An expense paid immediately out of cash or the bank.
 *
 *   Dr each expense account
 *       Cr one cash / bank account
 *
 * Deliberately NOT the credit-purchase workflow. A supplier invoice raises a payable and is
 * settled later by a supplier payment; this document has no payable stage at all, because
 * the money left at the same moment the expense was incurred. Conflating the two would put
 * a liability on the books that never existed.
 *
 * There is no total column: the total is the sum of the lines, derived every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_vouchers', function (Blueprint $table) {
            $table->id();

            $table->string('voucher_number', 50)->unique();
            $table->date('voucher_date');
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();

            // The cash or bank account the money left. Constrained to the CONFIGURATION row,
            // not to accounts directly, so an arbitrary asset account cannot be named here.
            $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();

            // Reporting only. This does NOT create a payable — see the class docblock.
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('payee_name', 150)->nullable();

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

            $table->index(['status', 'voucher_date'], 'ev_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_vouchers');
    }
};
