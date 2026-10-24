<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loans the factory owes (2026-10-24): bank loans with interest and machinery bought on
 * installments without it. A loan has no balance column — what is still owed is the amount
 * borrowed less the principal of the payments still standing. Installments vary: each payment
 * records what was actually paid and how much of it was interest. See docs/LOANS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('loans_account_id')->nullable()->after('stock_loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('loan_interest_account_id')->nullable()->after('loans_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('opening_balance_account_id')->nullable()->after('loan_interest_account_id')->constrained('accounts')->restrictOnDelete();
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_number', 30)->unique();
            $table->string('lender', 150);
            $table->string('purpose', 255)->nullable();
            $table->date('start_date');
            // RECEIVED: money paid into a cash/bank account; EXISTING: a loan already running,
            // entered with what is still owed.
            $table->string('funding', 20);
            $table->decimal('amount', 20, 6);
            $table->foreignId('cash_bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('has_interest')->default(true);
            // The usual installment and the day of the month it falls due — for reminders only;
            // what is actually paid each time is the payer's figure.
            $table->decimal('installment_amount', 20, 6)->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 30)->unique();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 20, 6);
            $table->decimal('interest_amount', 20, 6);
            $table->decimal('principal_amount', 20, 6);
            $table->foreignId('cash_bank_account_id')->constrained()->restrictOnDelete();
            $table->string('payment_method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('status', 20);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'status', 'payment_date']);
        });

        // One row per reminder sent, so a re-run never sends the same reminder twice.
        Schema::create('loan_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->date('due_date');
            $table->unsignedTinyInteger('days_before');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['loan_id', 'due_date', 'days_before']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_reminders');
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');

        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_balance_account_id');
            $table->dropConstrainedForeignId('loan_interest_account_id');
            $table->dropConstrainedForeignId('loans_account_id');
        });
    }
};
