<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A production type may now hold one active recipe PER INPUT PRODUCT.
 *
 * Coconuts are bought and kept by size (2026-10-24), and each size is dehusked into dehusked
 * coconuts of the same size and dried into the one copra stock. So dehusking and copra each have
 * a recipe per size, versioned independently: the version is unique per type AND input product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_recipes', function (Blueprint $table) {
            $table->unique(['production_type', 'input_product_id', 'version'], 'production_recipes_type_input_version_unique');
        });

        Schema::table('production_recipes', function (Blueprint $table) {
            $table->dropUnique(['production_type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('production_recipes', function (Blueprint $table) {
            $table->unique(['production_type', 'version']);
        });

        Schema::table('production_recipes', function (Blueprint $table) {
            $table->dropUnique('production_recipes_type_input_version_unique');
        });
    }
};
