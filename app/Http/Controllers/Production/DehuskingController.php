<?php

namespace App\Http\Controllers\Production;

use App\Actions\Production\SaveDehuskingOrder;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreDehuskingRequest;
use App\Http\Requests\Production\UpdateDehuskingRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\ProductionRecipe;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dehusking production.
 *
 * A draft moves no stock. Posting is a separate, explicit action handled by
 * DehuskingActionController -> PostDehuskingProduction.
 *
 * Output products, quantities and all cost figures are derived — never submitted. The
 * recipe supplies the products and ratios, and the ledger supplies the costs.
 */
class DehuskingController extends Controller
{
    public function __construct(
        private readonly SaveDehuskingOrder $saveOrder,
        private readonly StockQueryService $stock,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $search = (string) $request->string('search');
        $list = ListQuery::from($request)->period('production_date')->sortable([
            'number' => 'production_number',
            'date' => 'production_date',
            'warehouse' => fn (Builder $q, string $d) => $q->orderBy(Warehouse::select('code')->whereColumn('warehouses.id', 'production_orders.warehouse_id'), $d),
            'input_quantity' => fn (Builder $q, string $d) => $q->orderBy(ProductionInput::selectRaw('COALESCE(SUM(quantity), 0)')->whereColumn('production_inputs.production_order_id', 'production_orders.id'), $d),
            'status' => 'status',
            'input_value' => fn (Builder $q, string $d) => $q->orderBy(ProductionInput::selectRaw('COALESCE(SUM(total_cost), 0)')->whereColumn('production_inputs.production_order_id', 'production_orders.id'), $d),
            'output_value' => fn (Builder $q, string $d) => $q->orderBy(ProductionOutput::selectRaw('COALESCE(SUM(allocated_cost), 0)')->whereColumn('production_outputs.production_order_id', 'production_orders.id'), $d),
        ], 'date');

        $orders = $list->paginate(ProductionOrder::query()
            ->where('production_type', ProductionType::Dehusking)
            ->with(['warehouse:id,code', 'inputs', 'outputs'])
            ->search($search)
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($warehouseId, fn (Builder $q, int $id) => $q->where('warehouse_id', $id)))
            ->through(fn (ProductionOrder $order): array => $this->summarise($order));

        return Inertia::render('production/dehusking/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $status ?: '',
                'warehouse_id' => $warehouseId,
            ] + $list->filters(),
            'warehouses' => Warehouse::query()->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => ProductionStatus::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('production/dehusking/Create', $this->formOptions());
    }

    public function store(StoreDehuskingRequest $request): RedirectResponse
    {
        $order = $this->saveOrder->handle(null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dehusking order saved as draft.')]);

        return to_route('dehusking.show', $order);
    }

    public function show(ProductionOrder $dehusking): Response
    {
        $dehusking->load([
            'warehouse:id,code,name',
            'inputs.product:id,code,name,primary_uom_id',
            'inputs.product.primaryUom:id,code',
            'inputs.lot:id,lot_number',
            'outputs.product:id,code,name,primary_uom_id',
            'outputs.product.primaryUom:id,code',
            'outputs.lot:id,lot_number',
            'consumptionTransaction:id,reference',
            'outputTransaction:id,reference',
            'postedBy:id,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('production/dehusking/Show', [
            'order' => $this->detail($dehusking),
        ]);
    }

    public function edit(ProductionOrder $dehusking): Response
    {
        $this->saveOrder->assertEditable($dehusking);

        $dehusking->load('inputs');
        $input = $dehusking->inputs->first();

        return Inertia::render('production/dehusking/Edit', array_merge($this->formOptions(), [
            'order' => [
                'id' => $dehusking->id,
                'production_number' => $dehusking->production_number,
                'production_date' => $dehusking->production_date?->toDateString(),
                'warehouse_id' => $dehusking->warehouse_id,
                'input_product_id' => $input?->product_id,
                'lot_id' => $input?->lot_id,
                'quantity' => $input?->quantity,
                'labour_rate' => $dehusking->labour_rate,
                'notes' => $dehusking->notes,
            ],
        ]));
    }

    public function update(UpdateDehuskingRequest $request, ProductionOrder $dehusking): RedirectResponse
    {
        $this->saveOrder->handle($dehusking, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dehusking order updated.')]);

        return to_route('dehusking.show', $dehusking);
    }

    /**
     * Everything the create screen needs, including the recipe so the outputs and their
     * cost shares can be previewed read-only.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        // Only coconut lots that actually hold stock can be processed.
        $balances = $this->stock->balancesByLot()
            ->filter(fn (object $row): bool => DecimalMath::isPositive($row->quantity));

        return [
            'warehouses' => Warehouse::query()->operational()->orderBy('code')->get(['id', 'code', 'name']),
            // One recipe per coconut size; the operator picks which coconuts are dehusked.
            'recipes' => ProductionRecipe::activeForType(ProductionType::Dehusking)
                ->map(function (ProductionRecipe $recipe) use ($balances): array {
                    $inputProduct = $recipe->inputProduct;

                    return [
                        'input_product_id' => $inputProduct->id,
                        'input_code' => $inputProduct->code,
                        'input_name' => $inputProduct->name,
                        'input_uom' => $inputProduct->primaryUom?->code,
                        'input_tracks_lots' => $inputProduct->tracksLots(),
                        // Company-wide position of this size, shown for context on the form.
                        'input_stock' => $this->stock->quantityForProduct($inputProduct->id),
                        'lots' => $this->lotsHolding($inputProduct, $balances),
                        // Pre-fills the labour rate the operator confirms; null until configured.
                        'default_labour_rate' => $recipe->default_labour_rate,
                        'outputs' => $recipe->outputs->map(fn ($output): array => [
                            'code' => $output->product?->code,
                            'name' => $output->product?->name,
                            'uom' => $output->product?->primaryUom?->code,
                            'ratio' => $output->output_ratio,
                            'allocation_percent' => $output->allocation_percent,
                            'absorbs_labour' => $output->absorbs_labour,
                        ])->values(),
                    ];
                })
                ->values(),
        ];
    }

    /**
     * Lots of a lot-tracked input holding stock. Pooled coconut has none to choose from
     * (docs/INVENTORY_RULES.md §15).
     *
     * @param  Collection<int, object>  $balances
     * @return Collection<int, array<string, mixed>>
     */
    private function lotsHolding(Product $inputProduct, Collection $balances): Collection
    {
        return ! $inputProduct->tracksLots() ? collect() : InventoryLot::query()
            ->where('product_id', $inputProduct->id)
            ->whereIn('id', $balances->pluck('lot_id')->unique()->all())
            ->orderByDesc('id')
            ->get(['id', 'lot_number', 'product_id'])
            ->map(fn (InventoryLot $lot): array => [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'warehouses' => $balances->where('lot_id', $lot->id)
                    ->map(fn (object $row): array => [
                        'warehouse_id' => $row->warehouse_id,
                        'quantity' => $row->quantity,
                    ])->values(),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(ProductionOrder $order): array
    {
        return [
            'id' => $order->id,
            'production_number' => $order->production_number,
            'production_date' => $order->production_date?->toDateString(),
            'warehouse_code' => $order->warehouse?->code,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'input_quantity' => $order->inputQuantity(),
            // Values exist only once the ledger has priced the consumption.
            'input_value' => $order->status->reachedInventory() ? $order->inputValue() : null,
            'output_value' => $order->status->reachedInventory() ? $order->outputValue() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(ProductionOrder $order): array
    {
        $input = $order->inputs->first();

        return array_merge($this->summarise($order), [
            'notes' => $order->notes,
            'posted_by' => $order->postedBy?->name,
            'posted_at' => BusinessClock::display($order->posted_at),
            'created_by' => $order->createdBy?->name,
            'reversed_at' => BusinessClock::display($order->reversed_at),
            'labour_rate' => $order->labour_rate,
            'labour_cost' => $order->labour_cost,
            'consumption_reference' => $order->consumptionTransaction?->reference,
            'output_reference' => $order->outputTransaction?->reference,
            'can_post' => $order->status->canPost(),
            'can_reverse' => $order->status->canReverse() && ! $order->isReversed(),
            'can_edit' => $order->status->isEditable(),
            'can_cancel' => $order->status->canCancel(),
            'input' => $input === null ? null : [
                'product_code' => $input->product?->code,
                'product_name' => $input->product?->name,
                'uom' => $input->product?->primaryUom?->code,
                'lot_number' => $input->lot?->lot_number,
                'quantity' => $input->quantity,
                'unit_cost' => $input->unit_cost,
                'total_cost' => $input->total_cost,
            ],
            'outputs' => $order->outputs->map(fn ($output): array => [
                'id' => $output->id,
                'product_code' => $output->product?->code,
                'product_name' => $output->product?->name,
                'uom' => $output->product?->primaryUom?->code,
                'lot_number' => $output->lot?->lot_number,
                'quantity' => $output->quantity,
                'allocation_percent' => $output->allocation_percent,
                'allocated_cost' => $output->allocated_cost,
                'labour_cost' => $output->labour_cost,
                'unit_cost' => $output->unit_cost,
            ])->values(),
        ]);
    }
}
