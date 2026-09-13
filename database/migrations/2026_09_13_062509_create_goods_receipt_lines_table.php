<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods receipt lines.
     *
     * The cost columns record how the landed cost was arrived at, so a posted receipt can
     * always be explained:
     *
     *   material_cost    = quantity x unit_price
     *   allocated_landed_cost = header landed costs x (this material / total material)
     *   final_unit_cost       = (material_cost + allocated_landed_cost) / quantity
     *
     * `final_unit_cost` is what is posted to inventory.
     */
    public function up(): void
    {
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('goods_receipt_id')
                ->constrained('goods_receipts')
                ->cascadeOnDelete();

            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->constrained('purchase_order_lines')
                ->restrictOnDelete();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->decimal('quantity', 20, 3);
            $table->decimal('secondary_quantity', 20, 3)->nullable();
            $table->decimal('unit_price', 20, 6);
            $table->decimal('material_cost', 20, 6)->default(0);
            $table->decimal('allocated_landed_cost', 20, 6)->default(0);
            $table->decimal('final_unit_cost', 20, 6)->default(0);

            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('inventory_lots')
                ->restrictOnDelete();
            $table->string('supplier_lot_number', 100)->nullable();

            $table->timestamps();

            $table->index('goods_receipt_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
    }
};
