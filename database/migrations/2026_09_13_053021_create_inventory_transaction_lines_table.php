<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory ledger lines — the single source of truth for stock.
     *
     * Quantities are ALWAYS stored positive; `direction` (IN/OUT) alone decides whether
     * stock rises or falls. Because every OUT line stores the weighted-average unit cost
     * used at the moment it was posted, company stock and value are simple aggregates:
     *
     *   quantity = SUM(IN qty)        - SUM(OUT qty)
     *   value    = SUM(IN total_cost) - SUM(OUT total_cost)
     *
     * No ledger replay is required, and no authoritative stock column exists anywhere.
     */
    public function up(): void
    {
        Schema::create('inventory_transaction_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_transaction_id')
                ->constrained('inventory_transactions')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('inventory_lots')
                ->restrictOnDelete();

            $table->string('direction', 3);

            // Decimal, never float — see CLAUDE.md rule 6.
            $table->decimal('quantity', 20, 3);
            $table->decimal('secondary_quantity', 20, 3)->nullable();
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->decimal('total_cost', 20, 6)->default(0);

            $table->timestamps();

            // Supports the stock-on-hand and lot-balance queries.
            $table->index(['product_id', 'warehouse_id']);
            $table->index(['product_id', 'warehouse_id', 'lot_id']);
            $table->index(['product_id', 'direction']);
            $table->index('lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transaction_lines');
    }
};
