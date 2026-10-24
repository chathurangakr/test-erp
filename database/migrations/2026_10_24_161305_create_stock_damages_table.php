<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Damaged stock written off: torn bags, spoiled coconuts, wet copra.
 *
 * Recorded in one step — the stock leaves through an ADJUSTMENT_OUT ledger transaction and its
 * value is charged to the stock-loss expense — and corrected only by reversal. The lines keep
 * the value the ledger charged; they never price anything themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_damages', function (Blueprint $table) {
            $table->id();
            $table->string('damage_number', 30)->unique();
            $table->date('damage_date');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->string('status', 20);
            $table->foreignId('inventory_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_inventory_transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['damage_date', 'id']);
        });

        Schema::create('stock_damage_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_damage_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 20, 3);
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            // Read back from the ledger line, never typed: the weighted average at the moment
            // of writing off, and the authoritative total.
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_damage_lines');
        Schema::dropIfExists('stock_damages');
    }
};
