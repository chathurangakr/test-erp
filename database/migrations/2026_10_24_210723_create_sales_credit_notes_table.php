<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discounts and quality allowances (2026-10-25): a credit note reduces what a customer owes on
 * one of their invoices without any goods coming back — `Dr sales discounts and allowances /
 * Cr trade receivables`. One step, corrected by reversal, never deleted. The discount account is
 * `accounting_settings.sales_discount_account_id` (4190). See docs/ACCOUNTING_RULES.md §33.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('sales_discount_account_id')->nullable()->after('stock_loss_account_id')
                ->constrained('accounts')->restrictOnDelete();
        });

        Schema::create('sales_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_number', 30)->unique();
            $table->date('credit_date');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            // DISCOUNT or ALLOWANCE (quality).
            $table->string('kind', 20);
            $table->decimal('amount', 20, 6);
            $table->string('reason', 255);
            $table->string('status', 10);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['sales_invoice_id', 'status']);
            $table->index(['customer_id', 'credit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_notes');

        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_discount_account_id');
        });
    }
};
