<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a recipe produces, and how it shares the input's value.
     *
     *   output_ratio       — units of this output per unit of input. Dehusking is 1.0 for
     *                        both outputs, which is what encodes the confirmed 1:1:1 rule.
     *   allocation_percent — share of the consumed inventory value this output carries.
     *                        Must total exactly 100 across a recipe; enforced in the model
     *                        and asserted by tests.
     *
     * The 85 / 15 split for dehusking is a TEMPORARY, configurable placeholder until the
     * factory finalises its joint-product costing policy. See docs/BUSINESS_RULES.md §18.
     */
    public function up(): void
    {
        Schema::create('production_recipe_outputs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('production_recipe_id')
                ->constrained('production_recipes')
                ->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Decimal, never float — these drive inventory valuation.
            $table->decimal('output_ratio', 20, 6)->default(1);
            $table->decimal('allocation_percent', 9, 6);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['production_recipe_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_recipe_outputs');
    }
};
