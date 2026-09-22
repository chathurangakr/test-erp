<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prepare the production tables for copra, whose two outputs behave differently.
     *
     * Copra production is the first run where one output is DERIVED and the other is
     * MEASURED, and where an output carries a second unit:
     *
     *   COPRA      — primary KG, entered by the operator        (output_ratio NULL)
     *   RAW-SHELL  — primary NOS, one per coconut processed     (output_ratio 1.000000)
     *                secondary KG, entered by the operator
     *
     * 1. `production_outputs.secondary_quantity` records the measured shell weight. The
     *    inventory engine already carries a secondary quantity on every ledger line; this
     *    stores the same figure on the production document so the run can be read back
     *    without walking the ledger. It is NOT a conversion of the primary quantity — there
     *    is no fixed NOS-to-KG relationship for shell, and there must never be one.
     *
     * 2. `production_recipe_outputs.requires_secondary_quantity` says, as DATA, that an
     *    output must be weighed as well as counted. Without it the posting action would
     *    have to ask "is this product RAW-SHELL?", which would put a product code back into
     *    business logic. Product codes live only in ProductionRecipeSeeder.
     *
     * See docs/BUSINESS_RULES.md §20.
     */
    public function up(): void
    {
        Schema::table('production_outputs', function (Blueprint $table) {
            $table->decimal('secondary_quantity', 20, 3)->nullable()->after('quantity');
        });

        Schema::table('production_recipe_outputs', function (Blueprint $table) {
            $table->boolean('requires_secondary_quantity')->default(false)->after('output_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('production_recipe_outputs', function (Blueprint $table) {
            $table->dropColumn('requires_secondary_quantity');
        });

        Schema::table('production_outputs', function (Blueprint $table) {
            $table->dropColumn('secondary_quantity');
        });
    }
};
