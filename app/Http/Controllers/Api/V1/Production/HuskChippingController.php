<?php

namespace App\Http\Controllers\Api\V1\Production;

use App\Actions\Production\CancelProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Production\ReverseProduction;
use App\Actions\Production\SaveHuskChippingOrder;
use App\Enums\ProductionType;
use App\Http\Controllers\Api\V1\Production\Concerns\HandlesProductionOrders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreHuskChippingRequest;
use App\Http\Requests\Production\UpdateHuskChippingRequest;
use App\Http\Resources\V1\Production\ProductionOrderResource;
use App\Models\ProductionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Husk chipping over the API. Chip weights are the operator's measured figures, stored
 * exactly as entered; there is no husk-to-KG conversion. Waste is information only.
 *
 * The same validation, draft action, posting action, reversal and cancellation as the
 * website. Posting twice is refused by the document's state, so a retried request moves
 * stock once; the second call gets 409.
 */
class HuskChippingController extends Controller
{
    use HandlesProductionOrders;

    protected function type(): ProductionType
    {
        return ProductionType::HuskChipping;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->listOrders($request);
    }

    public function show(ProductionOrder $huskChipping): ProductionOrderResource
    {
        return $this->resource($this->ensureType($huskChipping));
    }

    public function store(StoreHuskChippingRequest $request, SaveHuskChippingOrder $save): JsonResponse
    {
        return $this->created($save->handle(null, $request->validated(), $request->user()->id));
    }

    public function update(UpdateHuskChippingRequest $request, ProductionOrder $huskChipping, SaveHuskChippingOrder $save): ProductionOrderResource
    {
        return $this->resource($save->handle($this->ensureType($huskChipping), $request->validated(), $request->user()->id));
    }

    public function post(Request $request, ProductionOrder $huskChipping, PostHuskChippingProduction $post): ProductionOrderResource
    {
        return $this->resource($post->handle($this->ensureType($huskChipping), $request->user()->id));
    }

    public function reverse(Request $request, ProductionOrder $huskChipping, ReverseProduction $reverse): ProductionOrderResource
    {
        return $this->resource($reverse->handle($this->ensureType($huskChipping), $request->user()->id));
    }

    public function cancel(Request $request, ProductionOrder $huskChipping, CancelProduction $cancel): ProductionOrderResource
    {
        return $this->resource($cancel->handle($this->ensureType($huskChipping), $request->user()->id));
    }
}
