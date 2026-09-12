<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product master.
     *
     * IMPORTANT: this table deliberately has NO stock quantity column. Stock is derived
     * from the inventory ledger (see docs/INVENTORY_RULES.md). An automated test asserts
     * that no stock column is ever added here.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->foreignId('primary_uom_id')
                ->constrained('units_of_measure')
                ->restrictOnDelete();

            $table->foreignId('secondary_uom_id')
                ->nullable()
                ->constrained('units_of_measure')
                ->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
