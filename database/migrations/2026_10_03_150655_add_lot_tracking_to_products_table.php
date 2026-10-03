<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a product's stock is kept in lots at all.
 *
 * TRUE (the default, and every product's behaviour until now) keeps lot selection and
 * traceability. FALSE makes the product one pooled stock: its movements carry no lot and no
 * screen asks for one. Which products are pooled is master data, set by ProductSeeder — never a
 * product code in application logic. Existing lots and their history are untouched.
 *
 * See docs/INVENTORY_RULES.md §15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tracks_lots')->default(true)->after('secondary_uom_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tracks_lots');
        });
    }
};
