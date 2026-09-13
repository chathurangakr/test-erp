<?php

namespace App\Actions\Inventory;

use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryTransaction;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted inventory transaction by posting a NEW, opposite one.
 *
 * The original is never modified beyond having its `reversed_by_id` audit pointer set, and
 * is never deleted. The reversal:
 *
 *  - mirrors every original line with the opposite direction;
 *  - reuses each original line's unit cost, so the reversal cancels the original exactly in
 *    both quantity and value (see docs/INVENTORY_RULES.md decision I-1);
 *  - is refused if the original was already reversed (also enforced by a unique index);
 *  - is refused for a reversal itself;
 *  - is refused if reversing would drive stock negative — the normal negative-stock guard
 *    in PostInventoryTransaction applies unchanged.
 *
 * See docs/INVENTORY_RULES.md §12.
 */
class ReverseInventoryTransaction
{
    public function __construct(private readonly PostInventoryTransaction $post) {}

    /**
     * @param  array{
     *     transaction_date?: Carbon|string|null,
     *     notes?: string|null,
     *     posted_by?: int|null,
     * }  $attributes
     */
    public function handle(InventoryTransaction $original, array $attributes = []): InventoryTransaction
    {
        if ($original->isReversal()) {
            throw ValidationException::withMessages([
                'transaction' => __('A reversal cannot itself be reversed.'),
            ]);
        }

        if ($original->isReversed()) {
            throw ValidationException::withMessages([
                'transaction' => __('Transaction :reference has already been reversed.', [
                    'reference' => $original->reference,
                ]),
            ]);
        }

        return DB::transaction(function () use ($original, $attributes): InventoryTransaction {
            // Re-read under lock so two concurrent reversals cannot both pass the check.
            $locked = InventoryTransaction::query()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->reversed_by_id !== null) {
                throw ValidationException::withMessages([
                    'transaction' => __('Transaction :reference has already been reversed.', [
                        'reference' => $locked->reference,
                    ]),
                ]);
            }

            $lines = [];

            foreach ($original->lines()->orderBy('id')->get() as $line) {
                $lines[] = (new PendingInventoryLine(
                    productId: $line->product_id,
                    warehouseId: $line->warehouse_id,
                    direction: $line->direction->opposite(),
                    quantity: DecimalMath::of($line->quantity),
                    lotId: $line->lot_id,
                    secondaryQuantity: $line->secondary_quantity === null
                        ? null
                        : DecimalMath::of($line->secondary_quantity),
                ))->withSystemUnitCost(DecimalMath::of($line->unit_cost));
            }

            $reversal = $this->post->handle(
                InventoryTransactionType::Reversal,
                $lines,
                [
                    'transaction_date' => $attributes['transaction_date'] ?? now(),
                    'source_type' => $original->source_type,
                    'source_id' => $original->source_id,
                    'reversal_of_id' => $original->getKey(),
                    'notes' => $attributes['notes'] ?? __('Reversal of :reference', [
                        'reference' => $original->reference,
                    ]),
                    'posted_by' => $attributes['posted_by'] ?? null,
                ],
            );

            // The only permitted write to a posted transaction: an append-only audit
            // pointer. The model's immutability guard allows this single field.
            $locked->reversed_by_id = $reversal->getKey();
            $locked->save();

            return $reversal;
        });
    }
}
