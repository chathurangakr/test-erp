<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLot;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates a lot for a product, generating the internal lot number.
 *
 * A supplier's own lot/batch reference is stored separately in `supplier_lot_number`, so
 * external numbering never has to collide with or replace the internal identifier.
 */
class CreateInventoryLot
{
    public function __construct(private readonly GenerateInventoryReference $references) {}

    /**
     * @param  array{
     *     supplier_lot_number?: string|null,
     *     source_type?: string|null,
     *     source_id?: int|null,
     *     manufactured_at?: Carbon|string|null,
     *     received_at?: Carbon|string|null,
     *     notes?: string|null,
     *     lot_number?: string|null,
     * }  $attributes
     */
    public function handle(Product $product, array $attributes = []): InventoryLot
    {
        return DB::transaction(fn (): InventoryLot => InventoryLot::create([
            'lot_number' => $attributes['lot_number'] ?? $this->references->forLot(),
            'product_id' => $product->id,
            'supplier_lot_number' => $attributes['supplier_lot_number'] ?? null,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => $attributes['source_id'] ?? null,
            'manufactured_at' => $attributes['manufactured_at'] ?? null,
            'received_at' => $attributes['received_at'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'is_active' => true,
        ]));
    }
}
