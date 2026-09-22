<?php

namespace App\Http\Controllers\Production;

use App\Actions\Production\GenerateProductionReference;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreCopraRequest;
use App\Http\Requests\Production\UpdateCopraRequest;
use App\Models\InventoryLot;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeOutput;
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
 * Copra production.
 *
 * Dehusked coconut (NOS) is dried into copra (KG) and leaves raw shell behind. The shell
 * COUNT follows the coconuts processed one for one — the factory's confirmed rule, held as
 * the recipe ratio — while the copra weight and the shell weight are both MEASURED by the
 * operator. No fixed NOS-to-KG conversion exists anywhere.
 *
 * A draft moves no stock. Posting is a separate, explicit action handled by
 * CopraActionController -> PostCopraProduction, which is where the consumed coconut value
 * is split between copra and shell.
 *
 * Cost figures are never submitted: they come from the ledger at posting time.
 */
class CopraController extends Controller
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
            ->where('production_type', ProductionType::Copra)
            ->with([
                'warehouse:id,code',
                'inputs',
                'outputs.product:id,code,primary_uom_id,secondary_uom_id',
                'outputs.product.primaryUom:id,code',
                'outputs.product.secondaryUom:id,code',
            ])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($warehouseId, fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('production_date', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('production_date', '<=', $d))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ProductionOrder $order): array => $this->summarise($order));

        return Inertia::render('production/copra/Index', [
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
            // The index columns come from the recipe, so no product code is written into a
            // controller or a screen.
            'outputColumns' => $this->outputColumns(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('production/copra/Create', $this->formOptions());
    }

    public function store(StoreCopraRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $recipe = $this->recipe();

        $order = DB::transaction(function () use ($data, $recipe): ProductionOrder {
            $order = ProductionOrder::create([
                'production_number' => $this->references->forProductionOrder(),
                'production_type' => ProductionType::Copra,
                'production_date' => $data['production_date'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => ProductionStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->writeLines($order, $data, $recipe);

            return $order;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copra production order saved as draft.')]);

        return to_route('copra.show', $order);
    }

    public function show(ProductionOrder $copra): Response
    {
        $copra->load([
            'warehouse:id,code,name',
            'inputs.product:id,code,name,primary_uom_id',
            'inputs.product.primaryUom:id,code',
            'inputs.lot:id,lot_number',
            'outputs.product:id,code,name,primary_uom_id,secondary_uom_id',
            'outputs.product.primaryUom:id,code',
            'outputs.product.secondaryUom:id,code',
            'outputs.lot:id,lot_number',
            'consumptionTransaction:id,reference',
            'outputTransaction:id,reference',
            'postedBy:id,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('production/copra/Show', [
            'order' => $this->detail($copra),
        ]);
    }

    public function edit(ProductionOrder $copra): Response
    {
        $this->assertEditable($copra);

        $copra->load(['inputs', 'outputs']);
        $input = $copra->inputs->first();

        return Inertia::render('production/copra/Edit', array_merge($this->formOptions(), [
            'order' => [
                'id' => $copra->id,
                'production_number' => $copra->production_number,
                'production_date' => $copra->production_date?->toDateString(),
                'warehouse_id' => $copra->warehouse_id,
                'lot_id' => $input?->lot_id,
                'quantity' => $input?->quantity,
                'notes' => $copra->notes,
                'outputs' => $copra->outputs
                    ->map(fn (ProductionOutput $output): array => [
                        'product_id' => $output->product_id,
                        'quantity' => $output->quantity,
                        'secondary_quantity' => $output->secondary_quantity,
                    ])->values(),
            ],
        ]));
    }

    public function update(UpdateCopraRequest $request, ProductionOrder $copra): RedirectResponse
    {
        $this->assertEditable($copra);

        $data = $request->validated();
        $recipe = $this->recipe();

        DB::transaction(function () use ($copra, $data, $recipe): void {
            $copra->update([
                'production_date' => $data['production_date'],
                'warehouse_id' => $data['warehouse_id'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Safe only because the order is still a draft — nothing has been consumed and
            // no output lot exists.
            $copra->inputs()->delete();
            $copra->outputs()->delete();

            $this->writeLines($copra, $data, $recipe);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copra production order updated.')]);

        return to_route('copra.show', $copra);
    }

    /**
     * Writes the draft's input and its outputs.
     *
     * The input product comes from the recipe, never from the request. A DERIVED output's
     * quantity is calculated here from the quantity processed and the recipe ratio — the
     * submitted value is refused by the form request, so there is nothing to fall back to.
     * A MEASURED quantity is stored exactly as entered. Costs stay null until the ledger
     * prices the batch.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeLines(ProductionOrder $order, array $data, ProductionRecipe $recipe): void
    {
        $processed = DecimalMath::quantity(DecimalMath::of($data['quantity']));

        $order->inputs()->create([
            'product_id' => $recipe->input_product_id,
            'lot_id' => $data['lot_id'],
            'quantity' => $processed,
            'unit_cost' => null,
            'total_cost' => null,
        ]);

        $configured = $recipe->outputs->keyBy(fn (ProductionRecipeOutput $output): int => (int) $output->product_id);

        foreach ($data['outputs'] as $output) {
            $config = $configured->get((int) $output['product_id']);

            if ($config === null) {
                continue;
            }

            $order->outputs()->create([
                'product_id' => $config->product_id,
                'lot_id' => null,
                'quantity' => $config->derivesQuantity()
                    ? DecimalMath::quantity(DecimalMath::mul($processed, DecimalMath::of($config->output_ratio)))
                    : DecimalMath::quantity(DecimalMath::of((string) $output['quantity'])),
                'secondary_quantity' => $config->requires_secondary_quantity
                    ? DecimalMath::quantity(DecimalMath::of((string) $output['secondary_quantity']))
                    : null,
                // The configured share, recorded for reference. Posting stores the share
                // actually used.
                'allocation_percent' => $config->allocation_percent,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }
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
        $recipe = ProductionRecipe::forType(ProductionType::Copra);

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'recipe' => __('No active copra production recipe is configured.'),
            ]);
        }

        return $recipe;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outputColumns(): array
    {
        return $this->recipe()->outputs->map(fn (ProductionRecipeOutput $output): array => [
            'product_id' => (int) $output->product_id,
            'code' => $output->product?->code,
            'name' => $output->product?->name,
            'uom' => $output->product?->primaryUom?->code,
            'secondary_uom' => $output->product?->secondaryUom?->code,
            // Null means the operator measures this output; a value means it is derived
            // from the quantity processed.
            'ratio' => $output->output_ratio,
            'requires_secondary_quantity' => $output->requires_secondary_quantity,
            'allocation_percent' => $output->allocation_percent,
        ])->values()->all();
    }

    /**
     * Everything the form needs: the coconut lots holding stock, what to weigh, the
     * configured cost shares, and the cost the consumption is expected to be valued at.
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
                'outputs' => $this->outputColumns(),
            ],
            'inputStock' => $this->stock->quantityForProduct($inputProduct->id),
            // What the input is expected to be valued at. The ledger decides for real at
            // posting time; this only lets the screen show an estimate.
            'inputUnitCost' => $this->stock->weightedAverageCostForProduct($inputProduct->id),
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
            'outputs_by_product' => $order->outputs->mapWithKeys(fn (ProductionOutput $output): array => [
                (string) $output->product_id => [
                    'quantity' => $output->quantity,
                    'secondary_quantity' => $output->secondary_quantity,
                ],
            ])->all(),
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
            'outputs' => $order->outputs->map(fn (ProductionOutput $output): array => [
                'id' => $output->id,
                'product_id' => (int) $output->product_id,
                'product_code' => $output->product?->code,
                'product_name' => $output->product?->name,
                'uom' => $output->product?->primaryUom?->code,
                'secondary_uom' => $output->product?->secondaryUom?->code,
                'lot_number' => $output->lot?->lot_number,
                'quantity' => $output->quantity,
                'secondary_quantity' => $output->secondary_quantity,
                'allocation_percent' => $output->allocation_percent,
                'allocated_cost' => $output->allocated_cost,
                // Inventory unit cost, always against the PRIMARY quantity.
                'unit_cost' => $output->unit_cost,
                // Informational only: what the allocated cost works out at per unit of the
                // measured weight. Never stored, never used as a weighted average.
                'informational_secondary_unit_cost' => $this->informationalSecondaryUnitCost($output),
            ])->values(),
        ]);
    }

    /**
     * Allocated cost divided by the measured weight, for display.
     *
     * Raw shell is valued in NOS because that is its primary unit, so this figure is NOT a
     * cost the inventory engine uses. It is shown because the factory thinks about shell in
     * kilograms, and it is labelled informational wherever it appears.
     */
    private function informationalSecondaryUnitCost(ProductionOutput $output): ?string
    {
        $secondary = $output->secondary_quantity;
        $allocated = $output->allocated_cost;

        if ($secondary === null || $allocated === null) {
            return null;
        }

        if (! DecimalMath::isPositive(DecimalMath::of($secondary))) {
            return null;
        }

        return DecimalMath::cost(DecimalMath::div(DecimalMath::of($allocated), DecimalMath::of($secondary)));
    }
}
