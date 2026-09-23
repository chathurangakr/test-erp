<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money received from customers.
 *
 * Always allocated to invoices — Version 1 has no deposits, prepayments or unallocated
 * receipts — so the allocated total must equal the receipt exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 50)->unique();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('receipt_date')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->restrictOnDelete();
            $table->foreignId('receipt_account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 20, 6);
            $table->string('status', 30)->index();
            $table->string('notes', 500)->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipts');
    }
};
