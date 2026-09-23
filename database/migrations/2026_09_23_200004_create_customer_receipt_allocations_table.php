<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which invoices a receipt settled, and by how much.
 *
 * Many-to-many on purpose: one receipt may settle several invoices, and one invoice may be
 * settled by several receipts. A reversed receipt's allocations stop counting without being
 * deleted, so the invoice's balance returns on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_receipt_id')->constrained('customer_receipts')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->decimal('amount', 20, 6);
            $table->timestamps();

            // Named explicitly: the generated name would exceed MariaDB's 64-character limit.
            $table->unique(['customer_receipt_id', 'sales_invoice_id'], 'cra_receipt_invoice_unique');
            $table->index('sales_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipt_allocations');
    }
};
