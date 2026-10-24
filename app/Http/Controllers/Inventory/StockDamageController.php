<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\RecordStockDamage;
use App\Actions\Inventory\ReverseStockDamage;
use App\Enums\StockDamageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockDamageRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\StockDamage;
use App\Models\StockDamageLine;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Damaged stock — written off in one step, corrected only by reversal. There is no edit.
 * See docs/INVENTORY_RULES.md §17.
 */
class StockDamageController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly StockQueryService $stock) {}

    public function index(Request $request): Response
    {
        $productId = $request->integer('product_id') ?: null;

        $damages = StockDamage::query()
            ->with(['warehouse:id,code,name', 'lines.product:id,code,name'])
            ->when($productId, fn ($query, int $id) => $query->whereHas('lines', fn ($lines) => $lines->where('product_id', $id)))
            ->orderByDesc('damage_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (StockDamage $damage): array => $this->summarise($damage));

        return Inertia::render('inventory/damages/Index', [
            'damages' => $damages,
            'filters' => ['product_id' => $productId],
            'products' => Product::query()->orderBy('code')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(): Response
    {
        $positive = fn (object $row): bool => DecimalMath::isPositive($row->quantity);

        // Lots that actually hold stock, so the picker only offers something to write off.
        $lotBalances = $this->stock->balancesByLot()->filter($positive);

        return Inertia::render('inventory/damages/Create', [
            'warehouses' => Warehouse::query()->operational()->orderBy('code')->get(['id', 'code', 'name', 'is_default']),
            'products' => Product::query()
                ->with('primaryUom:id,code')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'primary_uom_id', 'tracks_lots'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'uom' => $product->primaryUom?->code,
                    'tracks_lots' => $product->tracksLots(),
                ]),
            'balances' => $this->stock->balancesByProductAndWarehouse()->filter($positive)
                ->map(fn (object $row): array => [
                    'product_id' => $row->product_id,
                    'warehouse_id' => $row->warehouse_id,
                    'quantity' => $row->quantity,
                ])->values(),
            'lots' => InventoryLot::query()
                ->whereHas('product', fn ($query) => $query->where('tracks_lots', true))
                ->whereIn('id', $lotBalances->pluck('lot_id')->unique()->all())
                ->get(['id', 'lot_number', 'product_id'])
                ->map(fn (InventoryLot $lot): array => [
                    'id' => $lot->id,
                    'lot_number' => $lot->lot_number,
                    'product_id' => $lot->product_id,
                    'warehouses' => $lotBalances->where('lot_id', $lot->id)->map(fn (object $row): array => [
                        'warehouse_id' => $row->warehouse_id,
                        'quantity' => $row->quantity,
                    ])->values(),
                ])
                ->values(),
            'today' => BusinessClock::todayString(),
        ]);
    }

    public function store(StoreStockDamageRequest $request, RecordStockDamage $action): RedirectResponse
    {
        $damage = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Damaged stock written off.')]);

        return to_route('stock-damages.show', $damage);
    }

    public function show(StockDamage $stockDamage, RecordOperationalJournal $accounting): Response
    {
        $stockDamage->load([
            'warehouse:id,code,name',
            'lines.product:id,code,name',
            'lines.lot:id,lot_number',
            'lines.uom:id,code',
            'inventoryTransaction:id,reference',
            'reversalTransaction:id,reference',
            'createdBy:id,name',
            'reversedBy:id,name',
        ]);

        $journal = $accounting->originalJournalFor($stockDamage);

        return Inertia::render('inventory/damages/Show', [
            'damage' => $this->summarise($stockDamage) + [
                'notes' => $stockDamage->notes,
                'lines' => $stockDamage->lines->sortBy('id')->values()->map(fn (StockDamageLine $line): array => [
                    'id' => $line->id,
                    'product_code' => $line->product?->code,
                    'product_name' => $line->product?->name,
                    'lot_number' => $line->lot?->lot_number,
                    'quantity' => (string) $line->quantity,
                    'uom' => $line->uom?->code,
                    'unit_cost' => $line->unit_cost === null ? null : (string) $line->unit_cost,
                    'total_cost' => $line->total_cost === null ? null : (string) $line->total_cost,
                ]),
                'inventory_reference' => $stockDamage->inventoryTransaction?->reference,
                'reversal_reference' => $stockDamage->reversalTransaction?->reference,
                'journal_number' => $journal?->journal_number,
                'journal_id' => $journal?->id,
                'created_by' => $stockDamage->createdBy?->name,
                'posted_at' => BusinessClock::display($stockDamage->posted_at),
                'reversed_by' => $stockDamage->reversedBy?->name,
                'reversed_at' => BusinessClock::display($stockDamage->reversed_at),
                'can_reverse' => $stockDamage->status->canReverse(),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function reverse(StockDamage $stockDamage, ReverseStockDamage $action): RedirectResponse
    {
        $action->handle($stockDamage, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock damage reversed. The stock is back.')]);

        return to_route('stock-damages.show', $stockDamage);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(StockDamage $damage): array
    {
        $total = $damage->lines->reduce(
            fn (string $sum, StockDamageLine $line): string => DecimalMath::add($sum, DecimalMath::of($line->total_cost ?? '0')),
            DecimalMath::zero(),
        );

        return [
            'id' => $damage->id,
            'damage_number' => $damage->damage_number,
            'damage_date' => $damage->damage_date->toDateString(),
            'warehouse' => $damage->warehouse ? "{$damage->warehouse->code} — {$damage->warehouse->name}" : null,
            'reason' => $damage->reason,
            'products' => $damage->lines->map(fn (StockDamageLine $line): ?string => $line->product?->code)->filter()->unique()->values(),
            'total_cost' => DecimalMath::cost($total),
            'status' => $damage->status->value,
            'status_label' => $damage->status->label(),
            'is_reversed' => $damage->status === StockDamageStatus::Reversed,
        ];
    }
}
