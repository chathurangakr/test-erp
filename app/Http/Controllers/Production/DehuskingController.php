<?php

namespace App\Http\Controllers\Production;

use App\Actions\Production\SaveDehuskingOrder;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreDehuskingRequest;
use App\Http\Requests\Production\UpdateDehuskingRequest;
use App\Models\InventoryLot;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    private const PER_PAGE = 20;

    public function __construct(
        private readonly SaveDehuskingOrder $saveOrder,
        private readonly StockQueryService $stock,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $orders = ProductionOrder::query()
            ->where('production_type', ProductionType::Dehusking)
            ->with(['warehouse:id,code', 'inputs', 'outputs'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($warehouseId, fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('production_date', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('production_date', '<=', $d))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ProductionOrder $order): array => $this->summarise($order));

        return Inertia::render('production/dehusking/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
                'warehouse_id' => $warehouseId,
                'from' => $from,
                'to' => $to,
            ],
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
                'lot_id' => $input?->lot_id,
                'quantity' => $input?->quantity,
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
        $recipe = $this->saveOrder->recipe();
        $inputProduct = $recipe->inputProduct;

        // Only coconut lots that actually hold stock can be processed.
        $balances = $this->stock->balancesByLot()
            ->filter(fn (object $row): bool => DecimalMath::isPositive($row->quantity));

        $lots = InventoryLot::query()
            ->where('product_id', $inputProduct->id)
            ->whereIn('id', $balances->pluck('lot_id')->unique()->all())
            ->orderByDesc('id')
            ->get(['id', 'lot_number', 'product_id'])
            ->map(function (InventoryLot $lot) use ($balances): array {
                return [
                    'id' => $lot->id,
                    'lot_number' => $lot->lot_number,
                    'warehouses' => $balances->where('lot_id', $lot->id)
                        ->map(fn (object $row): array => [
                            'warehouse_id' => $row->warehouse_id,
                            'quantity' => $row->quantity,
                        ])->values(),
                ];
            })
            ->values();

        return [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'lots' => $lots,
            'recipe' => [
                'input_code' => $inputProduct->code,
                'input_name' => $inputProduct->name,
                'input_uom' => $inputProduct->primaryUom?->code,
                'outputs' => $recipe->outputs->map(fn ($output): array => [
                    'code' => $output->product?->code,
                    'name' => $output->product?->name,
                    'uom' => $output->product?->primaryUom?->code,
                    'ratio' => $output->output_ratio,
                    'allocation_percent' => $output->allocation_percent,
                ])->values(),
            ],
            // Company-wide coconut position, shown for context on the form.
            'inputStock' => $this->stock->quantityForProduct($inputProduct->id),
        ];
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
                'unit_cost' => $output->unit_cost,
            ])->values(),
        ]);
    }
}
