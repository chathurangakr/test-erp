<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales invoices — where revenue is recognised.
 *
 * A delivery moves stock and records what it cost; the invoice records what the customer
 * owes. They are deliberately separate events, which is what allows a delivery to be billed
 * later, partially, or across several invoices.
 *
 * There is NO stored outstanding balance: what an invoice still owes is derived from its
 * lines less the receipts allocated to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 50)->unique();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('invoice_date')->index();
            $table->date('due_date')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->restrictOnDelete();

            $table->string('customer_reference', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('status', 30)->index();

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
        Schema::dropIfExists('sales_invoices');
    }
};
