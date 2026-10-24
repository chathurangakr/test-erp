<?php

namespace App\Services\Sales;

use App\Enums\CustomerBagMovementKind;
use App\Enums\CustomerBagMovementStatus;
use App\Models\CustomerBagMovement;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * How many returnable bags each customer holds: given − returned − lost, over the movements
 * still POSTED. Derived every time, never stored — a customer has no bag column, just as it has
 * no receivable column. See docs/INVENTORY_RULES.md §18.
 */
class CustomerBagBalances
{
    public function held(int $customerId, int $productId): string
    {
        return $this->balances($customerId, $productId)->first()->quantity ?? DecimalMath::quantity('0');
    }

    /**
     * One row per customer and product with bags still out, largest first.
     *
     * @return Collection<int, object{customer_id: int, product_id: int, quantity: string}>
     */
    public function balances(?int $customerId = null, ?int $productId = null): Collection
    {
        $given = CustomerBagMovementKind::Given->value;

        return CustomerBagMovement::query()
            ->selectRaw('customer_id, product_id')
            ->selectRaw("SUM(CASE WHEN kind = '{$given}' THEN quantity ELSE -quantity END) as quantity")
            ->where('status', CustomerBagMovementStatus::Posted)
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->groupBy('customer_id', 'product_id')
            ->get()
            ->map(fn (CustomerBagMovement $row): object => (object) [
                'customer_id' => (int) $row->customer_id,
                'product_id' => (int) $row->product_id,
                'quantity' => DecimalMath::quantity(DecimalMath::of((string) ($row->getAttributes()['quantity'] ?? '0'))),
            ])
            ->filter(fn (object $row): bool => $customerId !== null || DecimalMath::isPositive($row->quantity))
            ->sort(fn (object $a, object $b): int => DecimalMath::compare($b->quantity, $a->quantity, 3))
            ->values();
    }
}
