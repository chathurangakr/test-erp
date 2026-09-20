<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales order lines.
     *
     * `delivered_quantity` is a progress counter maintained by delivery posting and
     * reversal. It is NOT stock: stock lives only in the inventory ledger.
     *
     * `pricing_basis` decides which recorded quantity revenue is calculated from — the
     * stocked primary quantity, or the secondary quantity for dual-UOM products such as
     * Raw Coconut Shell. See docs/BUSINESS_RULES.md §17.
     */
    public function up(): void
    {
        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')
                ->constrained('sales_orders')
                ->cascadeOnDelete(); // Lines belong to a draft header and have no meaning alone.

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->decimal('quantity', 20, 3);
            $table->decimal('secondary_quantity', 20, 3)->nullable();

            $table->string('pricing_basis', 20)->default('PRIMARY');
            $table->decimal('unit_price', 20, 6);
            $table->decimal('line_total', 20, 6);

            $table->decimal('delivered_quantity', 20, 3)->default(0);
            $table->decimal('delivered_secondary_quantity', 20, 3)->nullable();

            $table->timestamps();

            $table->index('sales_order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
    }
};
