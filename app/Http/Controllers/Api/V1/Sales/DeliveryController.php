<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Actions\Kiosk\RecordKioskEntry;
use App\Actions\Sales\CancelDelivery;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\ReverseDelivery;
use App\Actions\Sales\SaveDelivery;
use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Api\V1\Concerns\RecordsOfflineEntries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreDeliveryRequest;
use App\Http\Requests\Sales\UpdateDeliveryRequest;
use App\Http\Resources\V1\Sales\DeliveryResource;
use App\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Deliveries over the API.
 *
 * Posting goes through PostDelivery — the same weighted-average issue, the same negative-stock
 * guard, the same locks and the same cost-of-sales journal as the website. Posting twice is
 * refused by the document's state, so a retried request from a phone cannot issue stock
 * twice; the second call gets 409. `record` saves and posts an offline entry in one step, once
 * per Idempotency-Key.
 */
class DeliveryController extends Controller
{
    use PaginatesResults;
    use RecordsOfflineEntries;

    private const DETAIL = [
        'customer:id,code,name',
        'warehouse:id,code,name',
        'salesOrder:id,so_number',
        'lines.product:id,code,name,primary_uom_id,secondary_uom_id',
        'lines.product.primaryUom:id,code',
        'lines.product.secondaryUom:id,code',
        'lines.lot:id,lot_number',
        'lines.backflushProduct:id,code,name',
        'inventoryTransaction:id,reference',
        'reversalInventoryTransaction:id,reference',
        'createdBy:id,name',
        'postedBy:id,name',
        'reversedBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $deliveries = Delivery::query()
            ->with(['customer:id,code,name', 'warehouse:id,code,name', 'salesOrder:id,so_number'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('customer_id'), fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when($request->integer('warehouse_id'), fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereOnOrAfter('delivery_date', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereOnOrBefore('delivery_date', $d))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return DeliveryResource::collection($deliveries);
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($delivery->load(self::DETAIL));
    }

    public function store(StoreDeliveryRequest $request, SaveDelivery $save): JsonResponse
    {
        $delivery = $save->handle(null, $request->validated(), $request->user()->id);

        return (new DeliveryResource($delivery->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(UpdateDeliveryRequest $request, Delivery $delivery, SaveDelivery $save): DeliveryResource
    {
        return new DeliveryResource($save->handle($delivery, $request->validated(), $request->user()->id)->load(self::DETAIL));
    }

    public function record(Request $request, RecordKioskEntry $entries): JsonResponse
    {
        return $this->recordOnce(
            $request,
            'deliveries',
            Permission::SalesDeliveryCreate,
            StoreDeliveryRequest::class,
            fn (array $data, int $userId): Delivery => $entries->dispatch($data, $userId),
            fn (Delivery $delivery): DeliveryResource => new DeliveryResource($delivery->load(self::DETAIL)),
        );
    }

    public function post(Request $request, Delivery $delivery, PostDelivery $post): DeliveryResource
    {
        return new DeliveryResource($post->handle($delivery, $request->user()->id)->load(self::DETAIL));
    }

    public function reverse(Request $request, Delivery $delivery, ReverseDelivery $reverse): DeliveryResource
    {
        return new DeliveryResource($reverse->handle($delivery, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, Delivery $delivery, CancelDelivery $cancel): DeliveryResource
    {
        return new DeliveryResource($cancel->handle($delivery, $request->user()->id)->load(self::DETAIL));
    }
}
