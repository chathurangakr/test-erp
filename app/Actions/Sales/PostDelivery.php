<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\DeliveryStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductionRecipe;
use App\Models\SalesOrderLine;
use App\Services\Inventory\StockQueryService;
use App\Services\Sales\SalesOrderStatusUpdater;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft delivery: issues SALES_ISSUE movements and records what the goods cost.
 *
 * Everything happens inside ONE database transaction. If any step fails there is no
 * inventory movement, no sales order update and no partially posted delivery.
 *
 * Revenue and cost are calculated independently and must stay that way:
 *
 *   sales_value         = selling quantity x unit_price   (selling quantity per pricing basis)
 *   inventory_unit_cost = whatever weighted average the LEDGER used — never a typed value
 *   backflush_cost      = what the LEDGER charged for the raw material backflushed, if any
 *   cost_of_goods_sold  = PRIMARY quantity x inventory_unit_cost + backflush_cost
 *
 * BACKFLUSHING. A product made by a recipe that defers its input to delivery (husk chipping)
 * entered stock with its raw-material cost pending. Its delivery line therefore carries the
 * raw material used — "Raw husks used" — and posting issues that material from stock in a
 * separate BACKFLUSH_ISSUE transaction, at its weighted average, adding the cost to the
 * line's cost of goods sold. It happens inside the same database transaction as the sale, so
 * if the raw material is short the WHOLE delivery fails: never chips gone without their husk,
 * never husk gone without the sale. Which lines need it is read from the recipe, never from a
 * product code.
 *
 * Selling price never influences the weighted average cost: the engine costs OUT lines
 * itself and refuses a caller-supplied price.
 *
 * See docs/BUSINESS_RULES.md §16 and §19.
 */
class PostDelivery
{
    public function __construct(
        private readonly PostInventoryTransaction $postInventory,
        private readonly StockQueryService $stock,
        private readonly RecordOperationalJournal $accounting,
        private readonly SalesOrderStatusUpdater $statusUpdater,
    ) {}

    public function handle(Delivery $delivery, ?int $userId = null): Delivery
    {
        return DB::transaction(function () use ($delivery, $userId): Delivery {
            // Re-read under lock so two concurrent posts cannot both pass the draft check.
            $locked = Delivery::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $lines = $locked->lines()->with('product')->orderBy('id')->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => __('A delivery must contain at least one line before posting.'),
                ]);
            }

            $this->validateLines($locked, $lines);
            $this->validateSalesOrder($locked, $lines);
            $backflushInputs = $this->resolveBackflush($lines);

            $pendingLines = [];

            foreach ($lines as $line) {
                $pendingLines[] = new PendingInventoryLine(
                    productId: $line->product_id,
                    warehouseId: $locked->warehouse_id,
                    direction: InventoryDirection::Out,
                    quantity: DecimalMath::of($line->quantity),
                    lotId: $line->lot_id,
                    secondaryQuantity: $line->secondary_quantity === null
                        ? null
                        : DecimalMath::of($line->secondary_quantity),
                    // No unitCost: outgoing stock is valued by the engine at the weighted
                    // average in force, and supplying one would be rejected.
                );
            }

            $transaction = $this->postInventory->handle(
                InventoryTransactionType::SalesIssue,
                $pendingLines,
                [
                    'transaction_date' => $locked->delivery_date,
                    'source_type' => Delivery::class,
                    'source_id' => $locked->getKey(),
                    'notes' => RecordText::of('Delivery :reference', ['reference' => $locked->delivery_number]),
                    'posted_by' => $userId ?? auth()->id(),
                ],
            );

            // The ledger lines come back in the order they were submitted, so each one
            // corresponds to the delivery line at the same index. Read the cost the engine
            // actually used rather than recomputing it here — there must be exactly one
            // source of truth for inventory cost.
            $ledgerLines = $transaction->lines->values();

            // Then the raw material the delivered goods used, if any line defers it.
            $backflush = $this->postBackflush($locked, $lines, $backflushInputs, $userId);
            $backflushCosts = $backflush['costs'];

            foreach ($lines as $index => $line) {
                $ledgerLine = $ledgerLines[$index] ?? null;

                if ($ledgerLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => __('The inventory posting did not return a movement for every delivery line.'),
                    ]);
                }

                $unitCost = DecimalMath::cost(DecimalMath::of($ledgerLine->unit_cost));
                $backflushCost = $backflushCosts[$index] ?? null;

                // Cost follows the PRIMARY quantity, because that is what left stock — even
                // when the line is priced per KG. Backflushed raw material is added on top.
                $goodsCost = DecimalMath::cost(DecimalMath::mul(DecimalMath::of($line->quantity), $unitCost));

                $line->forceFill([
                    'sales_value' => $this->salesValueFor($line),
                    'inventory_unit_cost' => $unitCost,
                    'backflush_product_id' => isset($backflushInputs[$index]) ? $backflushInputs[$index]->id : null,
                    'backflush_cost' => $backflushCost,
                    'cost_of_goods_sold' => $backflushCost === null
                        ? $goodsCost
                        : DecimalMath::cost(DecimalMath::add($goodsCost, $backflushCost)),
                ])->save();
            }

            $this->statusUpdater->applyDelivery($locked, $lines, reverse: false);

            $locked->forceFill([
                'status' => DeliveryStatus::Posted,
                'inventory_transaction_id' => $transaction->getKey(),
                'backflush_inventory_transaction_id' => $backflush['transaction']?->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            // Dr cost of sales, Cr inventory — the physical issue only, including any raw
            // material backflushed. Revenue and receivables belong to the sales invoice, so the
            // selling price does not appear in this journal at all.
            $this->accounting->forDelivery($locked, $transaction, $userId, $backflush['transaction']);

            return $locked->refresh()->load(['lines', 'inventoryTransaction']);
        });
    }

    /**
     * Which lines backflush a raw material, and which one: index => raw material product.
     *
     * A line's product is an output of an active recipe that defers its input to delivery →
     * the operator must state the positive quantity of that input used. Any other line must
     * state none.
     *
     * @param  Collection<int, DeliveryLine>  $lines
     * @return array<int, Product>
     */
    private function resolveBackflush(Collection $lines): array
    {
        $inputs = [];

        foreach ($lines->values() as $index => $line) {
            $field = "lines.{$index}.backflush_quantity";
            $recipe = ProductionRecipe::backflushingFor($line->product_id);
            $quantity = $line->backflush_quantity === null ? null : DecimalMath::of($line->backflush_quantity);

            if ($recipe === null) {
                if ($quantity !== null) {
                    throw ValidationException::withMessages([
                        $field => __('Product :product uses no raw material at delivery, so no raw material quantity can be entered.', [
                            'product' => $line->product?->code ?? __('unknown'),
                        ]),
                    ]);
                }

                continue;
            }

            $input = $recipe->inputProduct;

            if ($quantity === null || ! DecimalMath::isPositive($quantity)) {
                throw ValidationException::withMessages([
                    $field => __('Enter how many :input were used to make the :product delivered.', [
                        'input' => $input->name,
                        'product' => $line->product?->code ?? __('goods'),
                    ]),
                ]);
            }

            // A backflush cannot ask anyone to pick a lot, so its raw material must be pooled.
            if ($input->tracksLots()) {
                throw ValidationException::withMessages([
                    $field => __('Raw material :product is kept in lots and cannot be backflushed. It must be pooled stock.', [
                        'product' => $input->code,
                    ]),
                ]);
            }

            $inputs[$index] = $input;
        }

        return $inputs;
    }

    /**
     * Issue the backflushed raw material, valued by the engine at its weighted average.
     *
     * The engine's negative-stock guard applies as for any issue; its errors are re-keyed to
     * the delivery line they belong to, so the operator sees them beside "Raw husks used".
     *
     * @param  Collection<int, DeliveryLine>  $lines
     * @param  array<int, Product>  $inputs
     * @return array{transaction: InventoryTransaction|null, costs: array<int, string>}
     */
    private function postBackflush(Delivery $delivery, Collection $lines, array $inputs, ?int $userId): array
    {
        if ($inputs === []) {
            return ['transaction' => null, 'costs' => []];
        }

        $lines = $lines->values();
        $pending = [];
        $lineIndexes = [];

        foreach ($inputs as $index => $input) {
            $pending[] = new PendingInventoryLine(
                productId: $input->id,
                warehouseId: $delivery->warehouse_id,
                direction: InventoryDirection::Out,
                quantity: DecimalMath::of($lines[$index]->backflush_quantity),
            );
            $lineIndexes[] = $index;
        }

        try {
            $transaction = $this->postInventory->handle(
                InventoryTransactionType::BackflushIssue,
                $pending,
                [
                    'transaction_date' => $delivery->delivery_date,
                    'source_type' => Delivery::class,
                    'source_id' => $delivery->getKey(),
                    'notes' => RecordText::of('Raw material backflushed by delivery :reference', [
                        'reference' => $delivery->delivery_number,
                    ]),
                    'posted_by' => $userId ?? auth()->id(),
                ],
            );
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $key => $errors) {
                $mapped = preg_match('/^lines\.(\d+)\./', $key, $match) === 1 && isset($lineIndexes[(int) $match[1]])
                    ? 'lines.'.$lineIndexes[(int) $match[1]].'.backflush_quantity'
                    : $key;
                $messages[$mapped] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }

        $costs = [];

        foreach ($transaction->lines->values() as $position => $ledgerLine) {
            $costs[$lineIndexes[$position]] = DecimalMath::cost(DecimalMath::of($ledgerLine->total_cost));
        }

        return ['transaction' => $transaction, 'costs' => $costs];
    }

    /**
     * Revenue for a line, from whichever quantity the pricing basis selects.
     */
    private function salesValueFor(DeliveryLine $line): string
    {
        return DecimalMath::cost(
            DecimalMath::mul($line->sellingQuantity(), DecimalMath::of($line->unit_price))
        );
    }

    private function assertPostable(Delivery $delivery): void
    {
        if (! $delivery->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft delivery can be posted. :reference is :status.', [
                    'reference' => $delivery->delivery_number,
                    'status' => $delivery->status->label(),
                ]),
            ]);
        }

        // Belt and braces against double posting.
        if ($delivery->inventory_transaction_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Delivery :reference has already been posted to inventory.', [
                    'reference' => $delivery->delivery_number,
                ]),
            ]);
        }

        if (! $delivery->customer?->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => __('Goods cannot be delivered to an inactive customer.'),
            ]);
        }

        if (! $delivery->warehouse?->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('Goods cannot be delivered from an inactive warehouse.'),
            ]);
        }
    }

    /**
     * @param  Collection<int, DeliveryLine>  $lines
     */
    private function validateLines(Delivery $delivery, Collection $lines): void
    {
        foreach ($lines as $index => $line) {
            $field = "lines.{$index}";
            $product = $line->product;

            if ($product === null) {
                throw ValidationException::withMessages([
                    $field.'.product_id' => __('The selected product does not exist.'),
                ]);
            }

            if (! DecimalMath::isPositive(DecimalMath::of($line->quantity))) {
                throw ValidationException::withMessages([
                    $field.'.quantity' => __('The delivered quantity must be greater than zero.'),
                ]);
            }

            if (DecimalMath::isNegative(DecimalMath::of($line->unit_price))) {
                throw ValidationException::withMessages([
                    $field.'.unit_price' => __('The selling price cannot be negative.'),
                ]);
            }

            $this->validatePricingBasis($line, $product, $field);
            $this->validateLot($delivery, $line, $field);
        }
    }

    /**
     * Pricing basis is governed by the product's units, never by a product code.
     */
    private function validatePricingBasis(DeliveryLine $line, $product, string $field): void
    {
        if (! $line->pricing_basis->isSecondary()) {
            return;
        }

        if ($product->secondary_uom_id === null) {
            throw ValidationException::withMessages([
                $field.'.pricing_basis' => __('Product :product has no secondary unit of measure and can only be priced on its primary unit.', [
                    'product' => $product->code,
                ]),
            ]);
        }

        if ($line->secondary_quantity === null
            || ! DecimalMath::isPositive(DecimalMath::of($line->secondary_quantity))) {
            throw ValidationException::withMessages([
                $field.'.secondary_quantity' => __('A secondary quantity is required when pricing on the secondary unit.'),
            ]);
        }
    }

    /**
     * Lot selection.
     *
     * A lot must be chosen whenever the product holds ANY lotted stock in this warehouse —
     * the system never silently assumes one. `null` is accepted only where no lot holds
     * stock, which is the unlotted case (opening balances, adjustments).
     *
     * The engine independently enforces lot/product match and per-lot negative stock; the
     * checks here exist to give a clearer message before any work is done.
     */
    private function validateLot(Delivery $delivery, DeliveryLine $line, string $field): void
    {
        // Pooled stock has no lots to choose from (docs/INVENTORY_RULES.md §15).
        if (! $line->product->tracksLots()) {
            if ($line->lot_id !== null) {
                throw ValidationException::withMessages([
                    $field.'.lot_id' => __(':product is kept as one pooled stock without lots, so no lot can be chosen.', [
                        'product' => $line->product->code,
                    ]),
                ]);
            }

            return;
        }

        if ($line->lot_id !== null) {
            $lot = InventoryLot::query()->whereKey($line->lot_id)->first();

            if ($lot === null) {
                throw ValidationException::withMessages([
                    $field.'.lot_id' => __('The selected lot does not exist.'),
                ]);
            }

            if ($lot->product_id !== $line->product_id) {
                throw ValidationException::withMessages([
                    $field.'.lot_id' => __('Lot :lot belongs to a different product.', ['lot' => $lot->lot_number]),
                ]);
            }

            return;
        }

        $lottedStock = $this->stock->balancesByLot()
            ->filter(fn (object $row): bool => $row->warehouse_id === $delivery->warehouse_id
                && DecimalMath::isPositive($row->quantity))
            ->filter(function (object $row) use ($line): bool {
                $lot = InventoryLot::query()->whereKey($row->lot_id)->first();

                return $lot !== null && $lot->product_id === $line->product_id;
            });

        if ($lottedStock->isNotEmpty()) {
            throw ValidationException::withMessages([
                $field.'.lot_id' => __('This product is held in :count lot(s). Select the lot being issued.', [
                    'count' => $lottedStock->count(),
                ]),
            ]);
        }
    }

    /**
     * @param  Collection<int, DeliveryLine>  $lines
     */
    private function validateSalesOrder(Delivery $delivery, Collection $lines): void
    {
        if ($delivery->sales_order_id === null) {
            return;
        }

        $order = $delivery->salesOrder()->lockForUpdate()->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'sales_order_id' => __('The linked sales order no longer exists.'),
            ]);
        }

        if (! $order->status->canDeliver()) {
            throw ValidationException::withMessages([
                'sales_order_id' => __('Sales order :reference is :status and cannot be delivered against.', [
                    'reference' => $order->so_number,
                    'status' => $order->status->label(),
                ]),
            ]);
        }

        if ($order->customer_id !== $delivery->customer_id) {
            throw ValidationException::withMessages([
                'sales_order_id' => __('The delivery customer does not match the sales order customer.'),
            ]);
        }

        // Over-delivery guard, accumulated per order line in case one delivery has several
        // lines pointing at the same order line.
        $requested = [];

        foreach ($lines as $line) {
            if ($line->sales_order_line_id === null) {
                continue;
            }

            $requested[$line->sales_order_line_id] = DecimalMath::add(
                $requested[$line->sales_order_line_id] ?? DecimalMath::zero(),
                DecimalMath::of($line->quantity),
            );
        }

        foreach ($requested as $orderLineId => $quantity) {
            $orderLine = SalesOrderLine::query()->whereKey($orderLineId)->lockForUpdate()->first();

            if ($orderLine === null || $orderLine->sales_order_id !== $order->getKey()) {
                throw ValidationException::withMessages([
                    'lines' => __('A delivery line refers to a sales order line that does not belong to this order.'),
                ]);
            }

            $outstanding = $orderLine->outstandingQuantity();

            if (DecimalMath::compare($quantity, DecimalMath::of($outstanding)) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('Delivering :quantity exceeds the outstanding quantity of :outstanding on the sales order.', [
                        'quantity' => DecimalMath::quantity($quantity),
                        'outstanding' => $outstanding,
                    ]),
                ]);
            }
        }
    }
}
