<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery lines.
     *
     * Revenue and cost are deliberately separate figures:
     *
     *   sales_value         = selling quantity x unit_price     (selling quantity chosen by
     *                                                            pricing_basis)
     *   inventory_unit_cost = the weighted average the ledger actually used at posting time
     *   cost_of_goods_sold  = PRIMARY quantity x inventory_unit_cost
     *
     * Cost always follows the primary quantity because that is what leaves stock, even when
     * the line is priced per KG. Both cost columns stay null until the delivery is posted —
     * a draft has no cost, because no stock has moved.
     */
    public function up(): void
    {
        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')
                ->constrained('deliveries')
                ->cascadeOnDelete();

            $table->foreignId('sales_order_line_id')
                ->nullable()
                ->constrained('sales_order_lines')
                ->restrictOnDelete();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('inventory_lots')
                ->restrictOnDelete();

            $table->decimal('quantity', 20, 3);
            $table->decimal('secondary_quantity', 20, 3)->nullable();

            $table->string('pricing_basis', 20)->default('PRIMARY');
            $table->decimal('unit_price', 20, 6);
            $table->decimal('sales_value', 20, 6)->default(0);

            // Populated by posting only — never entered by a user.
            $table->decimal('inventory_unit_cost', 20, 6)->nullable();
            $table->decimal('cost_of_goods_sold', 20, 6)->nullable();

            $table->timestamps();

            $table->index('delivery_id');
            $table->index('product_id');
            $table->index('lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_lines');
    }
};
