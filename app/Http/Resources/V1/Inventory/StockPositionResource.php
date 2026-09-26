<?php

namespace App\Http\Resources\V1\Inventory;

use App\Http\Resources\V1\ApiResource;
use Illuminate\Http\Request;

/**
 * One product in one warehouse: quantity and value derived from the inventory ledger by
 * StockQueryService. The weighted average cost is company-wide per product, so it repeats
 * across that product's warehouses rather than being recalculated per warehouse.
 *
 * The secondary quantity is informational and has no fixed conversion to the primary unit.
 */
class StockPositionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'product' => [
                'id' => $row['product']->id,
                'code' => $row['product']->code,
                'name' => $row['product']->name,
                'is_active' => $row['product']->is_active,
            ],
            'warehouse' => $this->ref($row['warehouse']),
            'quantity' => $this->quantity($row['quantity']),
            'primary_uom' => $row['product']->primaryUom?->code,
            'secondary_quantity' => $row['product']->secondary_uom_id === null ? null : $this->quantity($row['secondary_quantity']),
            'secondary_uom' => $row['product']->secondaryUom?->code,
            'weighted_average_cost' => $this->money($row['unit_cost']),
            'value' => $this->money($row['value']),
        ];
    }
}
