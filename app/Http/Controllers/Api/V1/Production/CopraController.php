<?php

namespace App\Http\Controllers\Api\V1\Production;

use App\Actions\Kiosk\RecordKioskEntry;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\PostCopraProduction;
use App\Actions\Production\ReverseProduction;
use App\Actions\Production\SaveCopraOrder;
use App\Enums\Permission;
use App\Enums\ProductionType;
use App\Http\Controllers\Api\V1\Concerns\RecordsOfflineEntries;
use App\Http\Controllers\Api\V1\Production\Concerns\HandlesProductionOrders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreCopraRequest;
use App\Http\Requests\Production\UpdateCopraRequest;
use App\Http\Resources\V1\Production\ProductionOrderResource;
use App\Models\ProductionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Copra over the API. The shell COUNT is derived (one per coconut) and a submitted count is
 * refused; copra KG and shell KG are measured and stored exactly as entered.
 *
 * The same validation, draft action, posting action, reversal and cancellation as the
 * website. Posting twice is refused by the document's state, so a retried request moves
 * stock once; the second call gets 409.
 */
class CopraController extends Controller
{
    use HandlesProductionOrders;
    use RecordsOfflineEntries;

    protected function type(): ProductionType
    {
        return ProductionType::Copra;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->listOrders($request);
    }

    public function show(ProductionOrder $copra): ProductionOrderResource
    {
        return $this->resource($this->ensureType($copra));
    }

    public function store(StoreCopraRequest $request, SaveCopraOrder $save): JsonResponse
    {
        return $this->created($save->handle(null, $request->validated(), $request->user()->id));
    }

    public function update(UpdateCopraRequest $request, ProductionOrder $copra, SaveCopraOrder $save): ProductionOrderResource
    {
        return $this->resource($save->handle($this->ensureType($copra), $request->validated(), $request->user()->id));
    }

    public function record(Request $request, RecordKioskEntry $entries): JsonResponse
    {
        return $this->recordOnce(
            $request,
            'copra',
            Permission::ProductionCreate,
            StoreCopraRequest::class,
            fn (array $data, int $userId): ProductionOrder => $entries->copra($data, $userId),
            fn (ProductionOrder $order): ProductionOrderResource => $this->resource($order),
        );
    }

    public function post(Request $request, ProductionOrder $copra, PostCopraProduction $post): ProductionOrderResource
    {
        return $this->resource($post->handle($this->ensureType($copra), $request->user()->id));
    }

    public function reverse(Request $request, ProductionOrder $copra, ReverseProduction $reverse): ProductionOrderResource
    {
        return $this->resource($reverse->handle($this->ensureType($copra), $request->user()->id));
    }

    public function cancel(Request $request, ProductionOrder $copra, CancelProduction $cancel): ProductionOrderResource
    {
        return $this->resource($cancel->handle($this->ensureType($copra), $request->user()->id));
    }
}
