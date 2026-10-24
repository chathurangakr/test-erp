<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\ReverseInventoryTransaction;
use App\Enums\CustomerBagMovementKind;
use App\Enums\CustomerBagMovementStatus;
use App\Models\Customer;
use App\Models\CustomerBagMovement;
use App\Services\Sales\CustomerBagBalances;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a bag movement recorded by mistake: the stock moves back at its original cost and a
 * loss's journal is mirrored exactly. Refused when undoing bags GIVEN would leave the customer
 * holding fewer than none — they were already returned or lost; reverse that first.
 */
class ReverseCustomerBagMovement
{
    public function __construct(
        private readonly ReverseInventoryTransaction $reverseInventory,
        private readonly RecordOperationalJournal $accounting,
        private readonly CustomerBagBalances $balances,
    ) {}

    public function handle(CustomerBagMovement $movement, ?int $userId = null): CustomerBagMovement
    {
        return DB::transaction(function () use ($movement, $userId): CustomerBagMovement {
            $locked = CustomerBagMovement::query()->whereKey($movement->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse() || $locked->reversal_inventory_transaction_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Bag movement :reference has already been reversed.', ['reference' => $locked->movement_number]),
                ]);
            }

            // Serialised with RecordCustomerBagMovement, which locks the same customer row.
            Customer::query()->whereKey($locked->customer_id)->lockForUpdate()->first();

            if ($locked->kind === CustomerBagMovementKind::Given) {
                $remaining = DecimalMath::sub($this->balances->held($locked->customer_id, $locked->product_id), (string) $locked->quantity);

                if (DecimalMath::isNegative($remaining)) {
                    throw ValidationException::withMessages([
                        'status' => __('Some of these bags were already returned or marked lost. Reverse those first.'),
                    ]);
                }
            }

            $reversal = $this->reverseInventory->handle($locked->inventoryTransaction()->firstOrFail(), [
                'notes' => RecordText::of('Reversal of bag movement :reference', ['reference' => $locked->movement_number]),
                'posted_by' => $userId ?? auth()->id(),
            ]);

            $this->accounting->reverseFor($locked, $userId);

            $locked->update([
                'status' => CustomerBagMovementStatus::Reversed,
                'reversal_inventory_transaction_id' => $reversal->id,
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
