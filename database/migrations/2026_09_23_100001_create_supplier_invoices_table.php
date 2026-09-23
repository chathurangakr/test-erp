<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier invoices.
 *
 * The supplier's own invoice number is unique **per supplier**, not globally: two suppliers
 * numbering their invoices "001" is ordinary, and a global constraint would reject the
 * second one for no reason. `supplier_invoice_number_normalised` holds a trimmed, uppercased
 * copy so "inv-001" and "INV-001 " cannot both be entered as separate invoices.
 *
 * There is NO stored outstanding balance. What an invoice still owes is derived from its
 * amount less the payments allocated to it, for the same reason accounts hold no balance and
 * products hold no stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('internal_invoice_number', 50)->unique();

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_invoice_number', 100);
            $table->string('supplier_invoice_number_normalised', 100);

            $table->date('invoice_date')->index();
            $table->date('due_date')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->restrictOnDelete();

            $table->string('status', 30)->index();
            $table->string('description', 500)->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            // A supplier cannot send the same invoice number twice.
            $table->unique(['supplier_id', 'supplier_invoice_number_normalised'], 'supplier_invoices_supplier_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};
