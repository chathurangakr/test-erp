<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Retire the QTY unit of measure in favour of NOS.
     *
     * QTY was created only because the original Raw Coconut Shell specification named it.
     * The business has since confirmed there are only two units: NOS for countable items
     * and KG for weight. "Qty" remains UI wording for a NOS count, not a separate unit.
     *
     * This migration is a safe, idempotent data fix: any product still pointing at QTY is
     * repointed at NOS, and QTY is then removed only if nothing references it. On a fresh
     * database where QTY never existed, every step is a no-op.
     */
    public function up(): void
    {
        $qtyId = DB::table('units_of_measure')->where('code', 'QTY')->value('id');

        if ($qtyId === null) {
            return;
        }

        $nosId = DB::table('units_of_measure')->where('code', 'NOS')->value('id');

        if ($nosId === null) {
            // Without a NOS unit to move to, leave the data untouched rather than guess.
            return;
        }

        DB::transaction(function () use ($qtyId, $nosId): void {
            DB::table('products')
                ->where('primary_uom_id', $qtyId)
                ->update(['primary_uom_id' => $nosId]);

            // A product cannot hold the same unit twice, so clear rather than repoint any
            // secondary QTY that would collide with an existing NOS primary.
            DB::table('products')
                ->where('secondary_uom_id', $qtyId)
                ->where('primary_uom_id', $nosId)
                ->update(['secondary_uom_id' => null]);

            DB::table('products')
                ->where('secondary_uom_id', $qtyId)
                ->update(['secondary_uom_id' => $nosId]);

            $stillReferenced = DB::table('products')
                ->where('primary_uom_id', $qtyId)
                ->orWhere('secondary_uom_id', $qtyId)
                ->exists();

            if (! $stillReferenced) {
                DB::table('units_of_measure')->where('id', $qtyId)->delete();
            }
        });
    }

    /**
     * Deliberately not reversible.
     *
     * Recreating QTY would reintroduce a unit the business has explicitly retired, and the
     * products previously pointing at it cannot be distinguished from those that always
     * used NOS. Rolling back leaves the data in its corrected state.
     */
    public function down(): void
    {
        //
    }
};
