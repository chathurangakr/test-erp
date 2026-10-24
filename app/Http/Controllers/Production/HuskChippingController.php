<?php

namespace App\Http\Controllers\Production;

use App\Actions\Production\SaveHuskChippingOrder;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreHuskChippingRequest;
use App\Http\Requests\Production\UpdateHuskChippingRequest;
use App\Models\ProductionOrder;
use App\Models\UnitOfMeasure;
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
 * Husk chipping production.
 *
 * Raw husk (NOS) is chipped into 7C, 9C and 10C (KG). Yields vary batch to batch, so the
 * operator enters the ACTUAL measured weights — nothing is derived from the husk count, and
 * the recipe deliberately holds no ratio to derive it from.
 *
 * A draft moves no stock. Posting is a separate, explicit action handled by
 * HuskChippingActionController -> PostHuskChippingProduction, which is where the consumed
 * husk value is shared across the grades this batch actually produced.
 *
 * Cost figures are never submitted: they come from the ledger at posting time.
 */
class HuskChippingController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly SaveHuskChippingOrder $saveOrder,
        private readonly StockQueryService $stock,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $orders = ProductionOrder::query()
            ->where('production_type', ProductionType::HuskChipping)
            ->with(['warehouse:id,code', 'inputs', 'outputs.product:id,code,primary_uom_id', 'outputs.product.primaryUom:id,code'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($warehouseId, fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('production_date', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('production_date', '<=', $d))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ProductionOrder $order): array => $this->summarise($order));

        return Inertia::render('production/husk-chipping/Index', [
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
        return Inertia::render('production/husk-chipping/Create', $this->formOptions());
    }

    public function store(StoreHuskChippingRequest $request): RedirectResponse
    {
        $order = $this->saveOrder->handle(null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Husk chipping order saved as draft.')]);

        return to_route('husk-chipping.show', $order);
    }

    public function show(ProductionOrder $huskChipping): Response
    {
        $huskChipping->load([
            'warehouse:id,code,name',
            'wasteUom:id,code,name',
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

        return Inertia::render('production/husk-chipping/Show', [
            'order' => $this->detail($huskChipping),
        ]);
    }

    public function edit(ProductionOrder $huskChipping): Response
    {
        $this->saveOrder->assertEditable($huskChipping);

        $huskChipping->load('outputs');

        return Inertia::render('production/husk-chipping/Edit', array_merge($this->formOptions(), [
            'order' => [
                'id' => $huskChipping->id,
                'production_number' => $huskChipping->production_number,
                'production_date' => $huskChipping->production_date?->toDateString(),
                'warehouse_id' => $huskChipping->warehouse_id,
                'waste_quantity' => $huskChipping->waste_quantity,
                'waste_uom_id' => $huskChipping->waste_uom_id,
                'notes' => $huskChipping->notes,
                'outputs' => $huskChipping->outputs
                    ->map(fn ($output): array => [
                        'product_id' => $output->product_id,
                        'quantity' => $output->quantity,
                    ])->values(),
            ],
        ]));
    }

    public function update(UpdateHuskChippingRequest $request, ProductionOrder $huskChipping): RedirectResponse
    {
        $this->saveOrder->handle($huskChipping, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Husk chipping order updated.')]);

        return to_route('husk-chipping.show', $huskChipping);
    }

    /**
     * Everything the form needs: the products a run may produce, each in its own unit.
     *
     * No husk lot and no husk quantity: chipping takes no raw husk at production — the husk is
     * backflushed when the outputs are delivered. The raw husk pool is shown for information.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $recipe = $this->saveOrder->recipe();
        $inputProduct = $recipe->inputProduct;

        return [
            'warehouses' => Warehouse::query()->operational()->orderBy('code')->get(['id', 'code', 'name']),
            'recipe' => [
                'input_code' => $inputProduct->code,
                'input_name' => $inputProduct->name,
                'input_uom' => $inputProduct->primaryUom?->code,
                'outputs' => $recipe->outputs->map(fn ($output): array => [
                    'product_id' => $output->product_id,
                    'code' => $output->product?->code,
                    'name' => $output->product?->name,
                    'uom' => $output->product?->primaryUom?->code,
                    'decimals' => (int) ($output->product?->primaryUom?->decimal_places ?? 0),
                ])->values(),
            ],
            // Waste is informational, and its measurement basis is still unconfirmed, so the
            // unit is chosen from the UOM master rather than assumed.
            'wasteUoms' => UnitOfMeasure::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
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
            // Totalled per unit, never across units: KG and litres are different stock and
            // nothing converts one into the other.
            'output_quantity' => $order->outputs
                ->groupBy(fn ($output): string => (string) $output->product?->primaryUom?->code)
                ->map(fn ($outputs, string $uom): string => DecimalMath::quantity($outputs->reduce(
                    fn (string $carry, $output): string => DecimalMath::add($carry, DecimalMath::of($output->quantity)),
                    DecimalMath::zero(),
                )).' '.$uom)
                ->implode(' · '),
            'waste_quantity' => $order->waste_quantity,
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
            'waste_uom' => $order->wasteUom?->code,
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
            // A run that took no raw husk: its outputs' material cost arrives at delivery.
            'pending_backflush' => $input === null,
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
