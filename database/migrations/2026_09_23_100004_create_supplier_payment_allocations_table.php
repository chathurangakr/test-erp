<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which invoices a payment settled, and by how much.
 *
 * Many-to-many on purpose: one payment may settle several invoices, and one invoice may be
 * settled by several payments. An invoice's outstanding balance is derived by subtracting the
 * allocations of payments that are still posted — a reversed payment's allocations stop
 * counting without being deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->decimal('amount', 20, 6);
            $table->timestamps();

            // Named explicitly: the generated name would exceed MariaDB's 64-character limit.
            $table->unique(['supplier_payment_id', 'supplier_invoice_id'], 'spa_payment_invoice_unique');
            $table->index('supplier_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
