<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier invoice lines — two kinds, and only two.
 *
 *  GRNI_MATCH      clears part of a goods receipt's accrual: `goods_receipt_id` is set.
 *                  Dr Goods Received Not Invoiced.
 *  DIRECT_EXPENSE  an invoice with no goods behind it — electricity, say: `debit_account_id`
 *                  is set. Dr that expense account.
 *
 * Both credit Trade Payables, which comes from configuration and is never on a line: a user
 * types what is being charged, never where the credit lands.
 *
 * No tax lines. VAT/SVAT is not modelled — see docs/ACCOUNTING_RULES.md §16.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();

            $table->string('line_type', 30)->index();

            // Exactly one of these is set, decided by line_type.
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('debit_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->string('description', 500)->nullable();
            $table->decimal('amount', 20, 6);

            $table->timestamps();

            // Named explicitly: the generated name would exceed MariaDB's 64-character limit.
            $table->index(['goods_receipt_id', 'supplier_invoice_id'], 'sil_grn_invoice_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_lines');
    }
};
