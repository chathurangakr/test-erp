<?php

namespace App\Http\Controllers\Kiosk;

use App\Actions\Kiosk\RecordKioskEntry;
use App\Actions\Production\SaveCopraOrder;
use App\Actions\Production\SaveDehuskingOrder;
use App\Actions\Production\SaveHuskChippingOrder;
use App\Enums\Permission;
use App\Enums\ProductionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskCopraRequest;
use App\Http\Requests\Kiosk\KioskDehuskingRequest;
use App\Http\Requests\Kiosk\KioskDeliveryRequest;
use App\Http\Requests\Kiosk\KioskGoodsReceiptRequest;
use App\Http\Requests\Kiosk\KioskHuskChippingRequest;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Services\Kiosk\KioskOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Simple mode's five jobs: receive, dispatch, dehusking, husk chipping, copra.
 *
 * Each job is a wizard page, a store that SAVES AND POSTS in one transaction
 * (RecordKioskEntry), and a result page for the posted document. Validation is the full ERP's
 * own FormRequest with the date fixed to today. The route requires the post permission; store
 * additionally requires the create permission, because Done does both.
 */
class KioskEntryController extends Controller
{
    public function __construct(
        private readonly KioskOptions $options,
        private readonly RecordKioskEntry $record,
    ) {}

    // ------------------------------------------------------------- receive goods

    public function receive(): Response
    {
        return Inertia::render('kiosk/Receive', KioskController::shared() + [
            'suppliers' => $this->options->suppliers(),
            'products' => $this->options->receivableProducts(),
            'warehouses' => $this->options->warehouses(),
        ]);
    }

    public function storeReceive(KioskGoodsReceiptRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::PurchasingGrnCreate->value);

        $receipt = $this->record->receive($request->validated(), $request->user()->id);

        return to_route('kiosk.receive.done', $receipt);
    }

    public function receiveDone(GoodsReceipt $goodsReceipt): Response
    {
        $goodsReceipt->load(['supplier:id,name', 'warehouse:id,name', 'lines.product.primaryUom', 'lines.product.secondaryUom']);

        return Inertia::render('kiosk/Done', KioskController::shared() + [
            'task' => 'receive',
            'document' => [
                'number' => $goodsReceipt->grn_number,
                'date' => $goodsReceipt->receipt_date?->toDateString(),
                'party' => $goodsReceipt->supplier?->name,
                'store' => $goodsReceipt->warehouse?->name,
                'status' => $goodsReceipt->status->value,
                'lines' => $goodsReceipt->lines->map(fn (GoodsReceiptLine $line): array => [
                    'product' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'uom' => $line->product?->primaryUom?->code,
                    'secondary_quantity' => $line->secondary_quantity,
                    'secondary_uom' => $line->product?->secondaryUom?->code,
                    'amount' => $line->material_cost,
                ])->values(),
            ],
        ]);
    }

    // ------------------------------------------------------------- dispatch

    public function dispatch(): Response
    {
        return Inertia::render('kiosk/Dispatch', KioskController::shared() + [
            'customers' => $this->options->customers(),
            'products' => $this->options->dispatchableProducts(),
            'warehouses' => $this->options->warehouses(),
        ]);
    }

    public function storeDispatch(KioskDeliveryRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::SalesDeliveryCreate->value);

        $delivery = $this->record->dispatch($request->validated(), $request->user()->id);

        return to_route('kiosk.dispatch.done', $delivery);
    }

    public function dispatchDone(Delivery $delivery): Response
    {
        $delivery->load(['customer:id,name', 'warehouse:id,name', 'lines.product.primaryUom', 'lines.product.secondaryUom', 'lines.lot:id,lot_number']);

        return Inertia::render('kiosk/Done', KioskController::shared() + [
            'task' => 'dispatch',
            'document' => [
                'number' => $delivery->delivery_number,
                'date' => $delivery->delivery_date?->toDateString(),
                'party' => $delivery->customer?->name,
                'store' => $delivery->warehouse?->name,
                'status' => $delivery->status->value,
                'lines' => $delivery->lines->map(fn (DeliveryLine $line): array => [
                    'product' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'uom' => $line->product?->primaryUom?->code,
                    'secondary_quantity' => $line->secondary_quantity,
                    'secondary_uom' => $line->product?->secondaryUom?->code,
                    'lot' => $line->lot?->lot_number,
                    'amount' => $line->sales_value,
                ])->values(),
            ],
        ]);
    }

    // ------------------------------------------------------------- production

    public function dehusking(SaveDehuskingOrder $save): Response
    {
        $save->recipe(); // Refuses, as before, when no recipe is configured at all.

        return $this->productionPage('dehusking', $this->options->productionVariants(ProductionType::Dehusking));
    }

    public function storeDehusking(KioskDehuskingRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::ProductionCreate->value);

        return to_route('kiosk.dehusking.done', $this->record->dehusk($request->validated(), $request->user()->id));
    }

    public function dehuskingDone(ProductionOrder $dehusking): Response
    {
        return $this->productionDone('dehusking', $dehusking);
    }

    public function huskChipping(SaveHuskChippingOrder $save): Response
    {
        return $this->productionPage('husk-chipping', [$this->options->production($save->recipe())]);
    }

    public function storeHuskChipping(KioskHuskChippingRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::ProductionCreate->value);

        return to_route('kiosk.husk-chipping.done', $this->record->chip($request->validated(), $request->user()->id));
    }

    public function huskChippingDone(ProductionOrder $huskChipping): Response
    {
        return $this->productionDone('husk-chipping', $huskChipping);
    }

    public function copra(SaveCopraOrder $save): Response
    {
        $save->recipe(); // Refuses, as before, when no recipe is configured at all.

        return $this->productionPage('copra', $this->options->productionVariants(ProductionType::Copra));
    }

    public function storeCopra(KioskCopraRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::ProductionCreate->value);

        return to_route('kiosk.copra.done', $this->record->copra($request->validated(), $request->user()->id));
    }

    public function copraDone(ProductionOrder $copra): Response
    {
        return $this->productionDone('copra', $copra);
    }

    /**
     * @param  list<array<string, mixed>>  $recipes  one per input product (coconut size)
     */
    private function productionPage(string $task, array $recipes): Response
    {
        return Inertia::render('kiosk/Production', KioskController::shared() + [
            'task' => $task,
            'recipes' => $recipes,
            'warehouses' => $this->options->warehouses(),
        ]);
    }

    private function productionDone(string $task, ProductionOrder $order): Response
    {
        $order->load(['warehouse:id,name', 'inputs.product.primaryUom', 'inputs.lot:id,lot_number', 'outputs.product.primaryUom', 'outputs.product.secondaryUom']);
        $input = $order->inputs->first();

        return Inertia::render('kiosk/Done', KioskController::shared() + [
            'task' => $task,
            'document' => [
                'number' => $order->production_number,
                'date' => $order->production_date?->toDateString(),
                'party' => null,
                'store' => $order->warehouse?->name,
                'status' => $order->status->value,
                'input' => $input === null ? null : [
                    'product' => $input->product?->name,
                    'quantity' => $input->quantity,
                    'uom' => $input->product?->primaryUom?->code,
                    'lot' => $input->lot?->lot_number,
                ],
                // What the posting actually produced, as the server calculated it.
                'lines' => $order->outputs->map(fn (ProductionOutput $output): array => [
                    'product' => $output->product?->name,
                    'quantity' => $output->quantity,
                    'uom' => $output->product?->primaryUom?->code,
                    'secondary_quantity' => $output->secondary_quantity,
                    'secondary_uom' => $output->product?->secondaryUom?->code,
                    'amount' => null,
                ])->values(),
            ],
        ]);
    }
}
