<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw-material backflushing at delivery.
 *
 * Husk chipping no longer takes raw husk out of stock. Instead, a delivery of a chipping
 * output records how many raw husks the delivered goods used; posting the delivery issues
 * them from stock at their weighted average and adds that cost to the line's cost of goods
 * sold — atomically with the delivery itself.
 *
 *  - the line stores which product was backflushed, how much, and what it cost (read back
 *    from the ledger, never recomputed);
 *  - the delivery points at the separate BACKFLUSH_ISSUE ledger transaction and, when
 *    reversed, at its reversal.
 *
 * See docs/BUSINESS_RULES.md §19 and docs/DATA_MODEL.md S-5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_lines', function (Blueprint $table) {
            $table->foreignId('backflush_product_id')
                ->nullable()
                ->after('cost_of_goods_sold')
                ->constrained('products')
                ->restrictOnDelete();
            $table->decimal('backflush_quantity', 20, 3)->nullable()->after('backflush_product_id');
            $table->decimal('backflush_cost', 20, 6)->nullable()->after('backflush_quantity');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('backflush_inventory_transaction_id')
                ->nullable()
                ->after('reversal_inventory_transaction_id')
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
            $table->foreignId('backflush_reversal_transaction_id')
                ->nullable()
                ->after('backflush_inventory_transaction_id')
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backflush_reversal_transaction_id');
            $table->dropConstrainedForeignId('backflush_inventory_transaction_id');
        });

        Schema::table('delivery_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backflush_product_id');
            $table->dropColumn(['backflush_quantity', 'backflush_cost']);
        });
    }
};
