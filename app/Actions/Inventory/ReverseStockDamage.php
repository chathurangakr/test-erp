<?php

namespace App\Actions\Inventory;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\StockDamageStatus;
use App\Models\StockDamage;
use App\Support\RecordText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a write-off recorded by mistake: the stock comes back at its original cost and the
 * journal is mirrored exactly. The record stays, marked REVERSED.
 */
class ReverseStockDamage
{
    public function __construct(
        private readonly ReverseInventoryTransaction $reverseInventory,
        private readonly RecordOperationalJournal $accounting,
    ) {}

    public function handle(StockDamage $damage, ?int $userId = null): StockDamage
    {
        return DB::transaction(function () use ($damage, $userId): StockDamage {
            $locked = StockDamage::query()->whereKey($damage->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse() || $locked->reversal_inventory_transaction_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Stock damage :reference has already been reversed.', ['reference' => $locked->damage_number]),
                ]);
            }

            $reversal = $this->reverseInventory->handle($locked->inventoryTransaction()->firstOrFail(), [
                'notes' => RecordText::of('Reversal of stock damage :reference', ['reference' => $locked->damage_number]),
                'posted_by' => $userId ?? auth()->id(),
            ]);

            $this->accounting->reverseFor($locked, $userId);

            $locked->update([
                'status' => StockDamageStatus::Reversed,
                'reversal_inventory_transaction_id' => $reversal->id,
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
