<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Production order header.
     *
     * A DRAFT moves no stock. Posting writes TWO inventory transactions, because the
     * ledger records one transaction type per transaction and production involves two:
     *
     *   inventory_transaction_id         -> PRODUCTION_CONSUMPTION (input leaves stock)
     *   output_inventory_transaction_id  -> PRODUCTION_OUTPUT      (outputs enter stock)
     *
     * with reversal_inventory_transaction_id and output_reversal_transaction_id pointing
     * at their respective reversals.
     *
     * Both are written inside a single database transaction, so the pair is atomic. Each
     * has its own reversal pointer for the same reason.
     */
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('production_number', 50)->unique();
            $table->string('production_type', 30);

            $table->date('production_date');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->string('status', 30);
            $table->text('notes')->nullable();

            $table->foreignId('inventory_transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
            $table->foreignId('output_inventory_transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
            $table->foreignId('reversal_inventory_transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
            // Deliberately shorter than the pattern above: MariaDB caps identifiers at 64
            // characters, and "production_orders_output_reversal_inventory_transaction_id
            // _foreign" would be 65.
            $table->foreignId('output_reversal_transaction_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reversed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('production_type');
            $table->index('production_date');
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
