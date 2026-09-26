<?php

namespace App\Http\Resources\V1\Inventory;

use App\Http\Resources\V1\ApiResource;
use App\Models\InventoryTransactionLine;
use Illuminate\Http\Request;

/**
 * One line of the inventory ledger. Quantities are always positive; `direction` decides the
 * sign. `total_cost` is the authoritative value of the line; `unit_cost` is only its rounded
 * per-unit representation.
 *
 * @mixin InventoryTransactionLine
 */
class LedgerLineResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transaction = $this->transaction;

        return [
            'id' => $this->id,
            'transaction' => $transaction === null ? null : [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'type' => $this->enum($transaction->transaction_type),
                'date' => $this->timestamp($transaction->transaction_date),
                'is_reversal' => $transaction->reversal_of_id !== null,
                'posted_by' => $this->userRef($transaction->postedBy),
            ],
            'product' => $this->ref($this->product),
            'warehouse' => $this->ref($this->warehouse),
            'lot_number' => $this->lot?->lot_number,
            'direction' => $this->direction->value,
            'quantity' => $this->quantity($this->quantity),
            'primary_uom' => $this->product?->primaryUom?->code,
            'secondary_quantity' => $this->quantity($this->secondary_quantity),
            'secondary_uom' => $this->product?->secondaryUom?->code,
            'unit_cost' => $this->money($this->unit_cost),
            'total_cost' => $this->money($this->total_cost),
        ];
    }
}
