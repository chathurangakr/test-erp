<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Production recipes — the single place that says what a production type consumes and
     * produces, and how input value is shared across the outputs.
     *
     * This exists so product codes and allocation percentages appear in exactly ONE place
     * (the seeder) instead of being scattered through controllers and services. Changing
     * the factory's costing policy is then a data change, not a code change.
     *
     * It is deliberately NOT a general BOM editor.
     */
    public function up(): void
    {
        Schema::create('production_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('production_type', 30)->unique();

            $table->foreignId('input_product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_recipes');
    }
};
