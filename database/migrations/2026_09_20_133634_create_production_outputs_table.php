<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a production order produced, and what share of the input value each output
     * carries.
     *
     *   allocated_cost = consumed value x recipe allocation percent
     *   unit_cost      = allocated_cost / quantity
     *
     * The allocated costs sum EXACTLY to the consumed value — the final output absorbs any
     * rounding remainder — so production neither creates nor destroys inventory value.
     * Each output gets its own lot, traceable back to this order.
     */
    public function up(): void
    {
        Schema::create('production_outputs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('production_order_id')
                ->constrained('production_orders')
                ->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('inventory_lots')
                ->restrictOnDelete();

            $table->decimal('quantity', 20, 3);
            $table->decimal('allocation_percent', 9, 6)->default(0);
            $table->decimal('allocated_cost', 20, 6)->default(0);
            $table->decimal('unit_cost', 20, 6)->default(0);

            $table->timestamps();

            $table->index('production_order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_outputs');
    }
};
