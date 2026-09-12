<?php

namespace App\Actions\Warehouses;

use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * Activates or deactivates a warehouse.
 *
 * Deactivating the default warehouse is refused: an inactive default contradicts the rule
 * that exactly one *active* warehouse is the default, and silently moving the default to
 * another warehouse would be inventing a business rule. The user must choose the new
 * default first. See docs/DATA_MODEL.md decision D-3.
 */
class ToggleWarehouseStatus
{
    public function handle(Warehouse $warehouse): Warehouse
    {
        if ($warehouse->is_active && $warehouse->is_default) {
            throw ValidationException::withMessages([
                'is_active' => __('The default warehouse cannot be deactivated. Make another warehouse the default first.'),
            ]);
        }

        $warehouse->update(['is_active' => ! $warehouse->is_active]);

        return $warehouse;
    }
}
