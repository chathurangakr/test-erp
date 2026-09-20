<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prepare the production tables for husk chipping, whose outputs are measured by the
     * operator rather than derived from the input.
     *
     * 1. `production_recipe_outputs.output_ratio` becomes NULLABLE. NULL means "this
     *    production type has NO fixed ratio — the operator enters the actual quantity".
     *    Encoding it as absent data rather than as some invented number is what makes a
     *    fixed husk-to-KG conversion structurally impossible.
     *
     * 2. `production_outputs.allocated_cost` and `unit_cost` become NULLABLE, so a draft's
     *    not-yet-costed output is distinguishable from an output genuinely costed at zero.
     *    Chipping stores operator quantities on the draft; costs arrive only at posting.
     *
     * 3. `production_orders` gains optional waste fields. Waste is recorded as production
     *    INFORMATION only: it creates no inventory line, no lot and no value. The unit
     *    points at the real UOM master rather than a free-text string, so no physically
     *    incorrect unit can be invented. How the factory measures waste is still TBD,
     *    which is why both fields are optional.
     */
    public function up(): void
    {
        Schema::table('production_recipe_outputs', function (Blueprint $table) {
            $table->decimal('output_ratio', 20, 6)->nullable()->default(null)->change();
        });

        Schema::table('production_outputs', function (Blueprint $table) {
            $table->decimal('allocated_cost', 20, 6)->nullable()->default(null)->change();
            $table->decimal('unit_cost', 20, 6)->nullable()->default(null)->change();
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('waste_quantity', 20, 3)->nullable()->after('notes');
            $table->foreignId('waste_uom_id')
                ->nullable()
                ->after('waste_quantity')
                ->constrained('units_of_measure')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waste_uom_id');
            $table->dropColumn('waste_quantity');
        });

        Schema::table('production_outputs', function (Blueprint $table) {
            $table->decimal('allocated_cost', 20, 6)->default(0)->change();
            $table->decimal('unit_cost', 20, 6)->default(0)->change();
        });

        Schema::table('production_recipe_outputs', function (Blueprint $table) {
            $table->decimal('output_ratio', 20, 6)->default(1)->change();
        });
    }
};
