<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Inventory\TransferStock;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\CustomerBagMovementKind;
use App\Enums\CustomerBagMovementStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Models\Customer;
use App\Models\CustomerBagMovement;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Sales\CustomerBagBalances;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records returnable bags given to, returned by or lost by a customer — in one step.
 *
 *   GIVEN     transfer store → "with customers"   (value-neutral, no journal)
 *   RETURNED  transfer "with customers" → store   (value-neutral, no journal)
 *   LOST      ADJUSTMENT_OUT from "with customers", Dr stock damage and losses / Cr inventory
 *
 * The bags stay the factory's stock while a customer holds them, so their value never leaves
 * the books until they are lost. A customer can return or lose no more than they hold; the
 * customer row is locked so two entries cannot both pass that check. See
 * docs/INVENTORY_RULES.md §18.
 */
class RecordCustomerBagMovement
{
    public function __construct(
        private readonly TransferStock $transfer,
        private readonly PostInventoryTransaction $postInventory,
        private readonly RecordOperationalJournal $accounting,
        private readonly GenerateSalesReference $references,
        private readonly CustomerBagBalances $balances,
    ) {}

    /**
     * @param  array{movement_date: string, kind: string, customer_id: int, product_id: int, warehouse_id?: int|null, quantity: string, note?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): CustomerBagMovement
    {
        return DB::transaction(function () use ($data, $userId): CustomerBagMovement {
            $kind = CustomerBagMovementKind::from($data['kind']);
            $date = CarbonImmutable::parse($data['movement_date']);
            $customer = Customer::query()->whereKey($data['customer_id'])->lockForUpdate()->firstOrFail();
            $product = Product::query()->findOrFail($data['product_id']);
            $quantity = DecimalMath::quantity(DecimalMath::of($data['quantity']));
            $store = $kind === CustomerBagMovementKind::Lost ? null : $this->store($data['warehouse_id'] ?? null);

            if (! $product->is_returnable) {
                throw ValidationException::withMessages([
                    'product_id' => __(':product is not a returnable item.', ['product' => $product->code]),
                ]);
            }

            if (! DecimalMath::isPositive($quantity)) {
                throw ValidationException::withMessages(['quantity' => __('The number of bags must be greater than zero.')]);
            }

            if ($kind === CustomerBagMovementKind::Given && ! $customer->is_active) {
                throw ValidationException::withMessages(['customer_id' => __('Bags cannot be given to an inactive customer.')]);
            }

            if ($kind->reducesHolding()) {
                $held = $this->balances->held($customer->id, $product->id);

                if (DecimalMath::compare($quantity, $held, 3) > 0) {
                    throw ValidationException::withMessages([
                        'quantity' => __(':customer holds only :held of these bags.', [
                            'customer' => $customer->name,
                            'held' => DecimalMath::round($held, 0),
                        ]),
                    ]);
                }
            }

            $movement = CustomerBagMovement::create([
                'movement_number' => $this->references->forBagMovement(Carbon::instance($date)),
                'movement_date' => $date->toDateString(),
                'kind' => $kind,
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'warehouse_id' => $store?->id,
                'quantity' => $quantity,
                'note' => $data['note'] ?? null,
                'status' => CustomerBagMovementStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $transaction = $this->moveStock($movement, $product, $store, $quantity, $userId);
            $movement->update(['inventory_transaction_id' => $transaction->id]);

            if ($kind === CustomerBagMovementKind::Lost) {
                $this->accounting->forLostBags($movement, $transaction, $userId);
            }

            return $movement->refresh();
        });
    }

    private function store(?int $warehouseId): Warehouse
    {
        $store = $warehouseId === null ? null : Warehouse::query()->operational()->find($warehouseId);

        if ($store === null) {
            throw ValidationException::withMessages(['warehouse_id' => __('Choose the store the bags leave from or come back to.')]);
        }

        return $store;
    }

    private function moveStock(CustomerBagMovement $movement, Product $product, ?Warehouse $store, string $quantity, ?int $userId): InventoryTransaction
    {
        $custody = Warehouse::customerCustody();
        $attributes = [
            'transaction_date' => $movement->movement_date->toDateString(),
            'source_type' => CustomerBagMovement::class,
            'source_id' => $movement->id,
            'posted_by' => $userId ?? auth()->id(),
        ];

        return match ($movement->kind) {
            CustomerBagMovementKind::Given => $this->transfer->handle($product, $store, $custody, $quantity, $attributes + [
                'notes' => RecordText::of('Bags given to :customer — :reference', [
                    'customer' => $movement->customer->code,
                    'reference' => $movement->movement_number,
                ]),
            ]),
            CustomerBagMovementKind::Returned => $this->transfer->handle($product, $custody, $store, $quantity, $attributes + [
                'notes' => RecordText::of('Bags returned by :customer — :reference', [
                    'customer' => $movement->customer->code,
                    'reference' => $movement->movement_number,
                ]),
            ]),
            CustomerBagMovementKind::Lost => $this->postInventory->handle(
                InventoryTransactionType::AdjustmentOut,
                [new PendingInventoryLine($product->id, $custody->id, InventoryDirection::Out, $quantity)],
                $attributes + [
                    'notes' => RecordText::of('Bags lost by :customer — :reference', [
                        'customer' => $movement->customer->code,
                        'reference' => $movement->movement_number,
                    ]),
                ],
            ),
        };
    }
}
