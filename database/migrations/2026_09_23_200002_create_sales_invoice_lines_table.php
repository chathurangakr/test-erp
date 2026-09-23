<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales invoice lines — each bills part of one delivered line.
 *
 * The **delivery line is authoritative** for the physical quantity, the pricing basis and the
 * money: its `sales_value` was computed when the goods went out and is never recalculated
 * here. That is what keeps the dual-unit Raw Shell case honest — the invoice bills the stored
 * value and introduces no second NOS-to-KG conversion.
 *
 * `product_id` is copied for reporting convenience only; the delivery line remains the source
 * of truth, and the revenue account is resolved from the product's mapping, never chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->foreignId('delivery_line_id')->constrained('delivery_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->string('description', 500)->nullable();
            $table->decimal('amount', 20, 6);

            $table->timestamps();

            // Named explicitly: the generated name would exceed MariaDB's 64-character limit.
            $table->index(['delivery_line_id', 'sales_invoice_id'], 'sil_delivery_invoice_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
    }
};
