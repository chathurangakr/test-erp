<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods receipt (GRN) header.
     *
     * May be raised against an approved purchase order or directly without one, because
     * factory purchases often happen on the spot.
     *
     * Header-level landed costs (transport, loading, other) are allocated across the lines
     * in proportion to line material value — never by quantity, because lines may use
     * different units of measure. See docs/BUSINESS_RULES.md §13.
     */
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('grn_number', 50)->unique();

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->constrained('purchase_orders')
                ->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->date('receipt_date');
            $table->string('supplier_delivery_reference', 100)->nullable();

            $table->decimal('transport_cost', 20, 6)->default(0);
            $table->decimal('loading_cost', 20, 6)->default(0);
            $table->decimal('other_cost', 20, 6)->default(0);

            $table->text('notes')->nullable();
            $table->string('status', 30);

            // Link to the ledger transaction this receipt created, and to its reversal.
            $table->foreignId('inventory_transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
            $table->foreignId('reversal_inventory_transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('supplier_id');
            $table->index('receipt_date');
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
