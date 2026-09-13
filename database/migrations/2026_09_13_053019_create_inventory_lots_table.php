<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot / batch traceability.
     *
     * A lot belongs to exactly one product. Internal lot numbers follow
     * LOT-YYYYMMDD-000001 and carry no business meaning — the source document is recorded
     * in source_type/source_id instead, so the permanent identifier never has to change.
     *
     * Lots are optional: inventory movements carry a nullable lot reference, and products
     * are not required to use lots.
     */
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->string('lot_number', 50)->unique();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->string('supplier_lot_number', 100)->nullable();

            // Polymorphic-style origin pointer. Deliberately not a Laravel morph relation:
            // the source documents do not exist yet, and the lot must not depend on them.
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->date('manufactured_at')->nullable();
            $table->date('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('product_id');
            $table->index('is_active');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
