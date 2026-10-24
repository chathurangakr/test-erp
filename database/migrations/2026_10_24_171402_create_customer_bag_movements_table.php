<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returnable bags lent to customers (2026-10-24).
 *
 * Bags go out free with the goods and must come back. They stay the factory's stock while a
 * customer holds them, so giving one MOVES it — a transfer from the store to the one warehouse
 * that holds customers' items — and a return moves it back; a bag never returned is written off
 * from there as a loss. Each movement names the customer, so what a customer holds is derived
 * from these rows, never stored. See docs/INVENTORY_RULES.md §18.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Lent to customers with the goods and expected back (polypropylene bags).
            $table->boolean('is_returnable')->default(false)->after('tracks_lots');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            // The place returnable items sit while customers hold them. Not a real store: it is
            // never offered on a document, and only bag movements post to it.
            $table->boolean('holds_customer_items')->default(false)->after('is_default');
        });

        Schema::create('customer_bag_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_number', 30)->unique();
            $table->date('movement_date');
            $table->string('kind', 20);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // The store the bags left from or came back to; null for a loss.
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 20, 3);
            $table->string('note', 255)->nullable();
            $table->string('status', 20);
            $table->foreignId('inventory_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_inventory_transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'product_id', 'status']);
            $table->index(['movement_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_bag_movements');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('holds_customer_items');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_returnable');
        });
    }
};
