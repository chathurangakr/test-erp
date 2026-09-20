<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery / dispatch note header.
     *
     * May be raised against an approved sales order or directly without one. A DRAFT moves
     * no stock; posting issues SALES_ISSUE movements through the inventory engine.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_number', 50)->unique();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sales_order_id')
                ->nullable()
                ->constrained('sales_orders')
                ->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->date('delivery_date');
            $table->string('customer_reference', 100)->nullable();
            $table->string('vehicle_number', 50)->nullable();
            $table->string('driver_name', 150)->nullable();

            $table->text('notes')->nullable();
            $table->string('status', 30);

            $table->foreignId('inventory_transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
            $table->foreignId('reversal_inventory_transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();

            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('customer_id');
            $table->index('delivery_date');
            $table->index('sales_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
