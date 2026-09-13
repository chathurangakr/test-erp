<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates the daily sequential identifiers used by the inventory engine.
 *
 *   Transactions: INV-YYYYMMDD-000001
 *   Lots:         LOT-YYYYMMDD-000001
 *
 * Neither format encodes business meaning such as "purchase" or "production". The origin
 * is recorded in source_type / source_id instead, so a document can be re-classified
 * without the permanent identifier becoming misleading.
 * See docs/INVENTORY_RULES.md §6 and §11.
 */
class GenerateInventoryReference
{
    private const SEQUENCE_LENGTH = 6;

    public function forTransaction(?Carbon $date = null): string
    {
        return $this->next('INV', InventoryTransaction::class, 'reference', $date);
    }

    public function forLot(?Carbon $date = null): string
    {
        return $this->next('LOT', InventoryLot::class, 'lot_number', $date);
    }

    /**
     * @param  class-string<InventoryTransaction|InventoryLot>  $model
     */
    private function next(string $prefix, string $model, string $column, ?Carbon $date): string
    {
        $stamp = ($date ?? now())->format('Ymd');
        $pattern = "{$prefix}-{$stamp}-";

        // Read the current maximum inside whatever transaction the caller already holds,
        // so two concurrent posts cannot both settle on the same sequence number.
        $latest = $model::query()
            ->where($column, 'like', $pattern.'%')
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        $sequence = $latest === null
            ? 1
            : ((int) substr((string) $latest, -self::SEQUENCE_LENGTH)) + 1;

        return $pattern.str_pad((string) $sequence, self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Reserve a reference outside an existing transaction.
     */
    public function forTransactionAtomically(?Carbon $date = null): string
    {
        return DB::transaction(fn (): string => $this->forTransaction($date));
    }
}
