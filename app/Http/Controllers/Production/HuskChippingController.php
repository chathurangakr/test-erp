<?php

namespace App\Http\Controllers\Production;

use App\Actions\Production\GenerateProductionReference;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreHuskChippingRequest;
use App\Http\Requests\Production\UpdateHuskChippingRequest;
use App\Models\InventoryLot;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        private readonly GenerateProductionReference $references,
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
            ->with(['warehouse:id,code', 'inputs', 'outputs.product:id,code'])
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
        $data = $request->validated();
        $recipe = $this->recipe();

        $order = DB::transaction(function () use ($data, $recipe): ProductionOrder {
            $order = ProductionOrder::create([
                'production_number' => $this->references->forProductionOrder(),
                'production_type' => ProductionType::HuskChipping,
                'production_date' => $data['production_date'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => ProductionStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'waste_quantity' => $this->wasteQuantity($data),
                'waste_uom_id' => $this->wasteQuantity($data) === null ? null : $data['waste_uom_id'],
                'created_by' => auth()->id(),
            ]);

            $this->writeLines($order, $data, $recipe);

            return $order;
        });

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
        $this->assertEditable($huskChipping);

        $huskChipping->load(['inputs', 'outputs']);
        $input = $huskChipping->inputs->first();

        return Inertia::render('production/husk-chipping/Edit', array_merge($this->formOptions(), [
            'order' => [
                'id' => $huskChipping->id,
                'production_number' => $huskChipping->production_number,
                'production_date' => $huskChipping->production_date?->toDateString(),
                'warehouse_id' => $huskChipping->warehouse_id,
                'lot_id' => $input?->lot_id,
                'quantity' => $input?->quantity,
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
        $this->assertEditable($huskChipping);

        $data = $request->validated();
        $recipe = $this->recipe();

        DB::transaction(function () use ($huskChipping, $data, $recipe): void {
            $huskChipping->update([
                'production_date' => $data['production_date'],
                'warehouse_id' => $data['warehouse_id'],
                'notes' => $data['notes'] ?? null,
                'waste_quantity' => $this->wasteQuantity($data),
                'waste_uom_id' => $this->wasteQuantity($data) === null ? null : $data['waste_uom_id'],
            ]);

            // Safe only because the order is still a draft — nothing has been consumed and
            // no output lot exists.
            $huskChipping->inputs()->delete();
            $huskChipping->outputs()->delete();

            $this->writeLines($huskChipping, $data, $recipe);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Husk chipping order updated.')]);

        return to_route('husk-chipping.show', $huskChipping);
    }

    /**
     * Writes the draft's input and its operator-entered outputs.
     *
     * The input product comes from the recipe, never from the request. Output quantities are
     * stored exactly as measured; their costs stay null until the ledger prices the batch.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeLines(ProductionOrder $order, array $data, ProductionRecipe $recipe): void
    {
        $order->inputs()->create([
            'product_id' => $recipe->input_product_id,
            'lot_id' => $data['lot_id'],
            'quantity' => DecimalMath::quantity(DecimalMath::of($data['quantity'])),
            'unit_cost' => null,
            'total_cost' => null,
        ]);

        $percentages = $recipe->outputs->pluck('allocation_percent', 'product_id');

        foreach ($data['outputs'] as $output) {
            $order->outputs()->create([
                'product_id' => $output['product_id'],
                'lot_id' => null,
                'quantity' => DecimalMath::quantity(DecimalMath::of((string) $output['quantity'])),
                // The configured share, recorded for reference. Posting replaces it with the
                // share actually used, which differs when a grade produced nothing.
                'allocation_percent' => $percentages->get((int) $output['product_id']),
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wasteQuantity(array $data): ?string
    {
        $waste = $data['waste_quantity'] ?? null;

        if ($waste === null || $waste === '') {
            return null;
        }

        return DecimalMath::quantity(DecimalMath::of((string) $waste));
    }

    private function assertEditable(ProductionOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft production order can be edited. :reference is :status.', [
                    'reference' => $order->production_number,
                    'status' => $order->status->label(),
                ]),
            ]);
        }
    }

    private function recipe(): ProductionRecipe
    {
        $recipe = ProductionRecipe::forType(ProductionType::HuskChipping);

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'recipe' => __('No active husk chipping recipe is configured.'),
            ]);
        }

        return $recipe;
    }

    /**
     * Everything the form needs: the husk lots holding stock, the grades to weigh, and the
     * configured cost shares so the allocation can be previewed.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $recipe = $this->recipe();
        $inputProduct = $recipe->inputProduct;

        $balances = $this->stock->balancesByLot()
            ->filter(fn (object $row): bool => DecimalMath::isPositive($row->quantity));

        $lots = InventoryLot::query()
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

        return [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'lots' => $lots,
            'recipe' => [
                'input_code' => $inputProduct->code,
                'input_name' => $inputProduct->name,
                'input_uom' => $inputProduct->primaryUom?->code,
                'outputs' => $recipe->outputs->map(fn ($output): array => [
                    'product_id' => $output->product_id,
                    'code' => $output->product?->code,
                    'name' => $output->product?->name,
                    'uom' => $output->product?->primaryUom?->code,
                    // Always null for chipping: there is no husk-to-KG conversion.
                    'ratio' => $output->output_ratio,
                    'allocation_percent' => $output->allocation_percent,
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
            'output_quantity' => DecimalMath::quantity($order->outputs->reduce(
                fn (string $carry, $output): string => DecimalMath::add($carry, DecimalMath::of($output->quantity)),
                DecimalMath::zero(),
            )),
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
            'posted_at' => $order->posted_at?->toDateTimeString(),
            'created_by' => $order->createdBy?->name,
            'reversed_at' => $order->reversed_at?->toDateTimeString(),
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
