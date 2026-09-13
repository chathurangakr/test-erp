<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase order lines.
     *
     * `received_quantity` is a progress counter maintained by goods-receipt posting and
     * reversal. It is NOT stock: stock lives only in the inventory ledger.
     */
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->cascadeOnDelete(); // Lines belong to a draft header and have no meaning alone.

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Decimal, never float — see CLAUDE.md rule 6.
            $table->decimal('quantity', 20, 3);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('line_total', 20, 6);
            $table->decimal('received_quantity', 20, 3)->default(0);

            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
