<?php

namespace App\Http\Controllers\Api\V1\Production;

use App\Actions\Kiosk\RecordKioskEntry;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\PostDehuskingProduction;
use App\Actions\Production\ReverseProduction;
use App\Actions\Production\SaveDehuskingOrder;
use App\Enums\Permission;
use App\Enums\ProductionType;
use App\Http\Controllers\Api\V1\Concerns\RecordsOfflineEntries;
use App\Http\Controllers\Api\V1\Production\Concerns\HandlesProductionOrders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreDehuskingRequest;
use App\Http\Requests\Production\UpdateDehuskingRequest;
use App\Http\Resources\V1\Production\ProductionOrderResource;
use App\Models\ProductionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dehusking over the API. Output products and quantities are derived by the recipe (1:1:1) at posting —
 * never accepted from the request.
 *
 * The same validation, draft action, posting action, reversal and cancellation as the
 * website. Posting twice is refused by the document's state, so a retried request moves
 * stock once; the second call gets 409.
 */
class DehuskingController extends Controller
{
    use HandlesProductionOrders;
    use RecordsOfflineEntries;

    protected function type(): ProductionType
    {
        return ProductionType::Dehusking;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->listOrders($request);
    }

    public function show(ProductionOrder $dehusking): ProductionOrderResource
    {
        return $this->resource($this->ensureType($dehusking));
    }

    public function store(StoreDehuskingRequest $request, SaveDehuskingOrder $save): JsonResponse
    {
        return $this->created($save->handle(null, $request->validated(), $request->user()->id));
    }

    public function update(UpdateDehuskingRequest $request, ProductionOrder $dehusking, SaveDehuskingOrder $save): ProductionOrderResource
    {
        return $this->resource($save->handle($this->ensureType($dehusking), $request->validated(), $request->user()->id));
    }

    public function record(Request $request, RecordKioskEntry $entries): JsonResponse
    {
        return $this->recordOnce(
            $request,
            'dehusking',
            Permission::ProductionCreate,
            StoreDehuskingRequest::class,
            fn (array $data, int $userId): ProductionOrder => $entries->dehusk($data, $userId),
            fn (ProductionOrder $order): ProductionOrderResource => $this->resource($order),
        );
    }

    public function post(Request $request, ProductionOrder $dehusking, PostDehuskingProduction $post): ProductionOrderResource
    {
        return $this->resource($post->handle($this->ensureType($dehusking), $request->user()->id));
    }

    public function reverse(Request $request, ProductionOrder $dehusking, ReverseProduction $reverse): ProductionOrderResource
    {
        return $this->resource($reverse->handle($this->ensureType($dehusking), $request->user()->id));
    }

    public function cancel(Request $request, ProductionOrder $dehusking, CancelProduction $cancel): ProductionOrderResource
    {
        return $this->resource($cancel->handle($this->ensureType($dehusking), $request->user()->id));
    }
}
