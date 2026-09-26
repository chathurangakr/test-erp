<?php

namespace App\Http\Resources\V1\Inventory;

use App\Http\Resources\V1\ApiResource;
use App\Models\InventoryLot;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * An inventory lot with its on-hand quantity per warehouse.
 *
 * Wraps ['lot' => InventoryLot, 'balances' => rows from StockQueryService::balancesByLot(),
 * 'warehouses' => warehouses keyed by id]. The balances are derived from the ledger by the
 * service; this resource only adds them up for display and formats them.
 */
class LotResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InventoryLot $lot */
        $lot = $this->resource['lot'];
        /** @var Collection<int, object> $balances */
        $balances = $this->resource['balances'];
        /** @var Collection<int, mixed> $warehouses */
        $warehouses = $this->resource['warehouses'];

        return [
            'id' => $lot->id,
            'lot_number' => $lot->lot_number,
            'supplier_lot_number' => $lot->supplier_lot_number,
            'product' => $this->ref($lot->product),
            'primary_uom' => $lot->product?->primaryUom?->code,
            'source_type' => $lot->source_type,
            'manufactured_at' => $this->date($lot->manufactured_at),
            'received_at' => $this->date($lot->received_at),
            'quantity' => $this->quantity($balances->reduce(
                fn (string $carry, object $row): string => DecimalMath::add($carry, $row->quantity),
                DecimalMath::zero(),
            )),
            'warehouses' => $balances
                ->filter(fn (object $row): bool => ! DecimalMath::isZero($row->quantity))
                ->map(fn (object $row): array => [
                    'warehouse' => $this->ref($warehouses->get($row->warehouse_id)),
                    'quantity' => $this->quantity($row->quantity),
                ])
                ->values(),
            'is_active' => $lot->is_active,
        ];
    }
}
