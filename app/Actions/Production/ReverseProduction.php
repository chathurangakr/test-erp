<?php

namespace App\Actions\Production;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\ReverseInventoryTransaction;
use App\Enums\ProductionStatus;
use App\Models\ProductionOrder;
use App\Support\RecordText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted production order.
 *
 * The order and its lines are never deleted or altered. Reversal undoes both halves:
 *
 *  1. the OUTPUT transaction first — taking the dehusked coconut and husk back OUT of
 *     stock. If either has already been sold or processed onward, the inventory engine's
 *     negative-stock guard rejects it and the whole reversal rolls back. This is the
 *     required behaviour: you cannot un-produce goods that have already left.
 *  2. then the CONSUMPTION — putting the whole coconut back IN.
 *
 * Both reversals reuse the original lines' unit costs, so value returns exactly as it left
 * rather than at whatever the weighted averages have drifted to since
 * (docs/INVENTORY_RULES.md I-1).
 *
 * Order matters: reversing the output first means a rejection happens before any coconut
 * has been restored. Both run inside one database transaction regardless.
 */
class ReverseProduction
{
    public function __construct(
        private readonly ReverseInventoryTransaction $reverseInventory,
        private readonly RecordOperationalJournal $accounting,
    ) {}

    public function handle(ProductionOrder $order, ?int $userId = null): ProductionOrder
    {
        return DB::transaction(function () use ($order, $userId): ProductionOrder {
            $locked = ProductionOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canReverse()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a posted production order can be reversed. :reference is :status.', [
                        'reference' => $locked->production_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            if ($locked->reversal_inventory_transaction_id !== null
                || $locked->output_reversal_transaction_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Production order :reference has already been reversed.', [
                        'reference' => $locked->production_number,
                    ]),
                ]);
            }

            $consumption = $locked->consumptionTransaction()->first();
            $output = $locked->outputTransaction()->first();

            if ($consumption === null || $output === null) {
                throw ValidationException::withMessages([
                    'status' => __('Production order :reference has no inventory transactions to reverse.', [
                        'reference' => $locked->production_number,
                    ]),
                ]);
            }

            // Outputs first: this is the step that can legitimately fail.
            $outputReversal = $this->reverseInventory->handle($output, [
                'notes' => RecordText::of('Reversal of production :reference output', [
                    'reference' => $locked->production_number,
                ]),
                'posted_by' => $userId ?? auth()->id(),
            ]);

            $consumptionReversal = $this->reverseInventory->handle($consumption, [
                'notes' => RecordText::of('Reversal of production :reference input', [
                    'reference' => $locked->production_number,
                ]),
                'posted_by' => $userId ?? auth()->id(),
            ]);

            $this->accounting->reverseFor($locked, $userId);

            $locked->forceFill([
                'status' => ProductionStatus::Reversed,
                'reversal_inventory_transaction_id' => $consumptionReversal->getKey(),
                'output_reversal_transaction_id' => $outputReversal->getKey(),
                'reversed_at' => now(),
                'reversed_by' => $userId ?? auth()->id(),
            ])->save();

            return $locked->refresh()->load(['inputs', 'outputs']);
        });
    }
}
