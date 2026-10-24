<?php

namespace App\Actions\Inventory;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\StockDamageStatus;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\StockDamage;
use App\Models\Warehouse;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes damaged stock off, in one step: torn bags, spoiled coconuts, wet copra.
 *
 * The stock leaves through an ADJUSTMENT_OUT transaction, valued by the engine at the weighted
 * average in force (the caller never prices it), and once accounting applies its value is
 * charged `Dr stock damage and losses / Cr inventory`. Not enough stock fails the whole record.
 * Everything is written in one database transaction. See docs/INVENTORY_RULES.md §17.
 */
class RecordStockDamage
{
    public function __construct(
        private readonly PostInventoryTransaction $postInventory,
        private readonly GenerateInventoryReference $references,
        private readonly RecordOperationalJournal $accounting,
    ) {}

    /**
     * @param  array{damage_date: string, warehouse_id: int, reason: string, notes?: string|null, lines: list<array{product_id: int, lot_id?: int|null, quantity: string}>}  $data
     */
    public function handle(array $data, ?int $userId = null): StockDamage
    {
        return DB::transaction(function () use ($data, $userId): StockDamage {
            $date = CarbonImmutable::parse($data['damage_date']);
            $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

            if (! $warehouse->is_active) {
                throw ValidationException::withMessages([
                    'warehouse_id' => __('Stock cannot be written off from an inactive warehouse.'),
                ]);
            }

            $lines = $this->validatedLines($data['lines'], $warehouse);

            $damage = StockDamage::create([
                'damage_number' => $this->references->forStockDamage(Carbon::instance($date)),
                'damage_date' => $date->toDateString(),
                'warehouse_id' => $warehouse->id,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'status' => StockDamageStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            foreach ($lines as $line) {
                $damage->lines()->create([
                    'product_id' => $line['product']->id,
                    'lot_id' => $line['lot_id'],
                    'quantity' => $line['quantity'],
                    'uom_id' => $line['product']->primary_uom_id,
                ]);
            }

            $transaction = $this->postInventory->handle(
                InventoryTransactionType::AdjustmentOut,
                array_map(fn (array $line): PendingInventoryLine => new PendingInventoryLine(
                    productId: $line['product']->id,
                    warehouseId: $warehouse->id,
                    direction: InventoryDirection::Out,
                    quantity: $line['quantity'],
                    lotId: $line['lot_id'],
                    // No unit cost: the engine values an issue at the weighted average.
                ), $lines),
                [
                    'transaction_date' => $date->toDateString(),
                    'source_type' => StockDamage::class,
                    'source_id' => $damage->id,
                    'notes' => RecordText::of('Stock damage :reference — :reason', [
                        'reference' => $damage->damage_number,
                        'reason' => $damage->reason,
                    ]),
                    'posted_by' => $userId ?? auth()->id(),
                ],
            );

            // The ledger lines come back in the order submitted; keep exactly what it charged.
            $ledgerLines = $transaction->lines->values();

            foreach ($damage->lines()->orderBy('id')->get()->values() as $index => $line) {
                $line->update([
                    'unit_cost' => $ledgerLines[$index]->unit_cost,
                    'total_cost' => $ledgerLines[$index]->total_cost,
                ]);
            }

            $damage->update(['inventory_transaction_id' => $transaction->id]);

            $this->accounting->forStockDamage($damage, $transaction, $userId);

            return $damage->refresh();
        });
    }

    /**
     * Each line's product, lot and positive quantity. A lot-tracked product needs the lot the
     * damaged stock came from; a pooled one has none (docs/INVENTORY_RULES.md §15).
     *
     * @param  list<array{product_id: int, lot_id?: int|null, quantity: string}>  $lines
     * @return list<array{product: Product, lot_id: int|null, quantity: string}>
     */
    private function validatedLines(array $lines, Warehouse $warehouse): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Add at least one damaged product.')]);
        }

        $validated = [];

        foreach (array_values($lines) as $index => $line) {
            $product = Product::query()->findOrFail($line['product_id']);
            $quantity = DecimalMath::quantity(DecimalMath::of($line['quantity']));
            $lotId = isset($line['lot_id']) && $line['lot_id'] !== '' ? (int) $line['lot_id'] : null;

            if (! DecimalMath::isPositive($quantity)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => __('The damaged quantity must be greater than zero.'),
                ]);
            }

            if (! $product->tracksLots() && $lotId !== null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => __('Product :product is pooled stock and has no lots.', ['product' => $product->code]),
                ]);
            }

            if ($product->tracksLots()) {
                $lot = $lotId === null ? null : InventoryLot::query()->find($lotId);

                if ($lot === null || $lot->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.lot_id" => __('Choose the lot the damaged :product came from.', ['product' => $product->code]),
                    ]);
                }
            }

            $validated[] = ['product' => $product, 'lot_id' => $lotId, 'quantity' => $quantity];
        }

        return $validated;
    }
}
