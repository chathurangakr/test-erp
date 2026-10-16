<?php

namespace App\Services\Kiosk;

use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeOutput;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * The choices simple mode offers — suppliers, customers, products, stores and lots.
 *
 * Read-only presentation data. Quantities come from StockQueryService (the inventory ledger);
 * nothing here decides a business rule, it only lists what the real validation will accept.
 */
class KioskOptions
{
    public function __construct(private readonly StockQueryService $stock) {}

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public function suppliers(): array
    {
        return Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Supplier $s): array => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->all();
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public function customers(): array
    {
        return Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Customer $c): array => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->all();
    }

    /**
     * Active stores, the default first — staff usually just confirm it.
     *
     * @return list<array{id: int, code: string, name: string, is_default: bool}>
     */
    public function warehouses(): array
    {
        return Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default'])
            ->map(fn (Warehouse $w): array => [
                'id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'is_default' => $w->is_default,
            ])->all();
    }

    /**
     * Products that may be received: every active product (the full ERP's rule).
     *
     * @return list<array<string, mixed>>
     */
    public function receivableProducts(): array
    {
        return Product::query()->where('is_active', true)->with(['primaryUom:id,code,decimal_places', 'secondaryUom:id,code,decimal_places'])
            ->orderBy('name')->get()
            ->map(fn (Product $p): array => $this->product($p))->all();
    }

    /**
     * Products with stock, each with its per-store quantities and lots, for dispatch.
     *
     * @return list<array<string, mixed>>
     */
    public function dispatchableProducts(): array
    {
        $balances = $this->stock->balancesByProductAndWarehouse()
            ->filter(fn (object $row): bool => DecimalMath::isPositive((string) $row->quantity));

        $lotBalances = $this->stock->balancesByLot()
            ->filter(fn (object $row): bool => DecimalMath::isPositive((string) $row->quantity));

        $lots = InventoryLot::query()->whereIn('id', $lotBalances->pluck('lot_id')->unique()->all())->get()->keyBy('id');

        // Products whose dispatch backflushes a raw material — staff then type how much.
        $backflush = ProductionRecipe::backflushInputsByOutput();

        return Product::query()->whereIn('id', $balances->pluck('product_id')->unique()->all())
            ->with(['primaryUom:id,code,decimal_places', 'secondaryUom:id,code,decimal_places'])->orderBy('name')->get()
            ->map(fn (Product $p): array => $this->product($p) + [
                'stock' => $balances->where('product_id', $p->id)->map(fn (object $row): array => [
                    'warehouse_id' => (int) $row->warehouse_id,
                    'quantity' => DecimalMath::quantity((string) $row->quantity),
                ])->values()->all(),
                // Pooled stock has no lots to choose (docs/INVENTORY_RULES.md §15).
                'lots' => $p->tracksLots() ? $this->lotCards($lotBalances, $lots->where('product_id', $p->id)) : [],
                'backflush' => $backflush->has($p->id) ? $this->product($backflush->get($p->id)) : null,
            ])->all();
    }

    /**
     * Every product the offline phone app may need, with its last known stock: each active
     * product, plus an inactive one that still holds stock (it may be dispatched, never received).
     *
     * One list rather than the kiosk's receive / dispatch pair, because offline a worker may
     * receive a product and dispatch it before the phone has synced. The figures are a snapshot;
     * the ERP still refuses an entry that exceeds real stock when it arrives.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        $balances = $this->stock->balancesByProductAndWarehouse()
            ->filter(fn (object $row): bool => DecimalMath::isPositive((string) $row->quantity));

        $lotBalances = $this->stock->balancesByLot()
            ->filter(fn (object $row): bool => DecimalMath::isPositive((string) $row->quantity));

        $lots = InventoryLot::query()->whereIn('id', $lotBalances->pluck('lot_id')->unique()->all())->get()->keyBy('id');
        $backflush = ProductionRecipe::backflushInputsByOutput();

        return Product::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $balances->pluck('product_id')->unique()->all()))
            ->with(['primaryUom:id,code,decimal_places', 'secondaryUom:id,code,decimal_places'])->orderBy('name')->get()
            ->map(fn (Product $p): array => $this->product($p) + [
                'is_active' => $p->is_active,
                'tracks_lots' => $p->tracksLots(),
                'backflush' => $backflush->has($p->id) ? $this->product($backflush->get($p->id)) : null,
                'total' => DecimalMath::quantity($this->stock->quantityForProduct($p->id)),
                'secondary_total' => $p->secondary_uom_id === null
                    ? null
                    : DecimalMath::quantity($this->stock->secondaryQuantityForProduct($p->id)),
                'stock' => $balances->where('product_id', $p->id)->map(fn (object $row): array => [
                    'warehouse_id' => (int) $row->warehouse_id,
                    'quantity' => DecimalMath::quantity((string) $row->quantity),
                ])->values()->all(),
                'lots' => $p->tracksLots() ? $this->lotCards($lotBalances, $lots->where('product_id', $p->id)) : [],
            ])->all();
    }

    /**
     * The lots of a recipe's input product that hold stock, for the production screens.
     *
     * @return array<string, mixed>
     */
    public function production(ProductionRecipe $recipe): array
    {
        $recipe->loadMissing(['inputProduct.primaryUom', 'outputs.product.primaryUom', 'outputs.product.secondaryUom']);

        $lotBalances = $this->stock->balancesByLot()
            ->filter(fn (object $row): bool => DecimalMath::isPositive((string) $row->quantity));

        $lots = InventoryLot::query()->where('product_id', $recipe->input_product_id)
            ->whereIn('id', $lotBalances->pluck('lot_id')->unique()->all())->get()->keyBy('id');

        return [
            'input' => $this->product($recipe->inputProduct),
            // No lots for a pooled input; none at all for a run that takes no input now.
            'lots' => $recipe->inputProduct->tracksLots() && ! $recipe->consumesInputAtDelivery()
                ? $this->lotCards($lotBalances, $lots)
                : [],
            'consumes_input' => ! $recipe->consumesInputAtDelivery(),
            'input_tracks_lots' => $recipe->inputProduct->tracksLots(),
            'labour' => $recipe->labourOutput() === null ? null : [
                'default_rate' => $recipe->default_labour_rate,
            ],
            'outputs' => $recipe->outputs->map(fn (ProductionRecipeOutput $output): array => $this->product($output->product) + [
                // Derived outputs are calculated by the server and must never be typed.
                'derived' => $output->derivesQuantity(),
                'requires_secondary_quantity' => $output->requires_secondary_quantity,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, object>  $lotBalances
     * @param  Collection<int, InventoryLot>  $lots
     * @return list<array<string, mixed>>
     */
    private function lotCards(Collection $lotBalances, Collection $lots): array
    {
        return $lots->sortByDesc('id')->map(fn (InventoryLot $lot): array => [
            'id' => $lot->id,
            'lot_number' => $lot->lot_number,
            'supplier_lot_number' => $lot->supplier_lot_number,
            'received_at' => $lot->received_at?->toDateString() ?? $lot->manufactured_at?->toDateString(),
            'stock' => $lotBalances->where('lot_id', $lot->id)->map(fn (object $row): array => [
                'warehouse_id' => (int) $row->warehouse_id,
                'quantity' => DecimalMath::quantity((string) $row->quantity),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'uom' => $product->primaryUom?->code,
            // How many decimals the keypad allows, from the unit master — never from a code.
            'decimals' => (int) ($product->primaryUom?->decimal_places ?? 3),
            'secondary_uom' => $product->secondaryUom?->code,
            'secondary_decimals' => (int) ($product->secondaryUom?->decimal_places ?? 3),
        ];
    }
}
