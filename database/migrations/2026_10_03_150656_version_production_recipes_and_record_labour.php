<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recipes become versioned, and dehusking labour becomes part of production cost.
 *
 *  - A production type may now hold several recipe VERSIONS; exactly one is active. Old
 *    versions are deactivated, never deleted, and every posted run keeps the figures it
 *    stored. Each new run records the version it used.
 *  - `input_consumption` says WHEN a recipe's input leaves stock: at production (dehusking,
 *    copra) or later, at delivery (husk chipping — backflushing).
 *  - `default_labour_rate` pre-fills the per-unit labour rate the operator confirms on a run;
 *    `absorbs_labour` marks the output that carries that labour as its cost.
 *  - A run stores the rate it used and the labour cost calculated from it.
 *
 * See docs/BUSINESS_RULES.md §18-§19 and docs/DATA_MODEL.md P-14.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_recipes', function (Blueprint $table) {
            $table->dropUnique(['production_type']);
            $table->unsignedSmallInteger('version')->default(1)->after('production_type');
            $table->string('input_consumption', 30)->default('AT_PRODUCTION')->after('input_product_id');
            $table->decimal('default_labour_rate', 20, 6)->nullable()->after('input_consumption');
            $table->unique(['production_type', 'version']);
            $table->index(['production_type', 'is_active']);
        });

        Schema::table('production_recipe_outputs', function (Blueprint $table) {
            $table->boolean('absorbs_labour')->default(false)->after('allocation_percent');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('production_recipe_id')
                ->nullable()
                ->after('production_type')
                ->constrained('production_recipes')
                ->restrictOnDelete();
            $table->decimal('labour_rate', 20, 6)->nullable()->after('waste_uom_id');
            $table->decimal('labour_cost', 20, 6)->nullable()->after('labour_rate');
        });

        Schema::table('production_outputs', function (Blueprint $table) {
            $table->decimal('labour_cost', 20, 6)->nullable()->after('allocated_cost');
        });
    }

    public function down(): void
    {
        Schema::table('production_outputs', function (Blueprint $table) {
            $table->dropColumn('labour_cost');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_recipe_id');
            $table->dropColumn(['labour_rate', 'labour_cost']);
        });

        Schema::table('production_recipe_outputs', function (Blueprint $table) {
            $table->dropColumn('absorbs_labour');
        });

        Schema::table('production_recipes', function (Blueprint $table) {
            $table->dropIndex(['production_type', 'is_active']);
            $table->dropUnique(['production_type', 'version']);
            $table->dropColumn(['version', 'input_consumption', 'default_labour_rate']);
            $table->unique('production_type');
        });
    }
};
