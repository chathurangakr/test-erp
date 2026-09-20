<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a production order consumed.
     *
     * `unit_cost` is the weighted average the LEDGER used when the input was issued, read
     * back after posting — never typed. Both cost columns stay null on a draft.
     */
    public function up(): void
    {
        Schema::create('production_inputs', function (Blueprint $table) {
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
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();

            $table->timestamps();

            $table->index('production_order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_inputs');
    }
};
