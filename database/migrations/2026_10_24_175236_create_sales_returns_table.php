<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods a customer brought back (2026-10-24), against the delivery line that sent them.
 *
 * The customer is credited at the price they were charged; good goods return to stock at the
 * cost they left with, damaged ones are a loss. The credit is settled by reducing an invoice
 * (also how an exchange for other goods is settled) or by money back. Recorded in one step and
 * corrected by reversal. See docs/ACCOUNTING_RULES.md §32.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 30)->unique();
            $table->date('return_date');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Where the good goods went back to; the lot they left from, if lot-tracked.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('good_quantity', 20, 3);
            $table->decimal('damaged_quantity', 20, 3);
            // The priced quantity returned, for a line priced on its secondary unit (KG).
            $table->decimal('secondary_quantity', 20, 3)->nullable();
            $table->decimal('unit_price', 20, 6);
            $table->decimal('credit_amount', 20, 6);
            // Read from the delivery line: what the goods cost when they left.
            $table->decimal('unit_cost', 20, 6);
            $table->decimal('good_cost', 20, 6);
            $table->decimal('damaged_cost', 20, 6);
            $table->string('settlement', 20);
            // The invoice the delivery line was billed on, and the one the credit reduces.
            $table->foreignId('original_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('credit_invoice_id')->nullable()->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('cash_bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('reason', 255);
            $table->string('status', 20);
            $table->foreignId('inventory_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_inventory_transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['credit_invoice_id', 'status']);
            $table->index(['delivery_line_id', 'status']);
            $table->index(['return_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
