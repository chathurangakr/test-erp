<?php

namespace App\Actions\Warehouses;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes one warehouse the default, guaranteeing exactly one active default exists.
 *
 * The invariant is enforced here, inside a transaction, rather than by a database
 * constraint: MariaDB has no partial unique index and SQLite's syntax differs, so a
 * database-level rule would leave the test schema weaker than production.
 * See docs/DATA_MODEL.md decision D-2.
 */
class SetDefaultWarehouse
{
    public function handle(Warehouse $warehouse): Warehouse
    {
        if (! $warehouse->is_active) {
            throw ValidationException::withMessages([
                'is_default' => __('An inactive warehouse cannot be the default warehouse.'),
            ]);
        }

        return DB::transaction(function () use ($warehouse): Warehouse {
            // Clearing first and setting second means no moment exists with two defaults.
            Warehouse::query()
                ->where('is_default', true)
                ->whereKeyNot($warehouse->getKey())
                ->update(['is_default' => false]);

            $warehouse->forceFill(['is_default' => true])->save();

            return $warehouse->refresh();
        });
    }
}
