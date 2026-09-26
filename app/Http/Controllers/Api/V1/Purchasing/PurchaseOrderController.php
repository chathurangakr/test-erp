<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Actions\Purchasing\ApprovePurchaseOrder;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseOrderRequest;
use App\Http\Requests\Purchasing\UpdatePurchaseOrderRequest;
use App\Http\Resources\V1\Purchasing\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Purchase orders over the API — the same actions and validation as the website.
 *
 * Authorization is declared on each route (routes/api/v1/purchasing.php); business-state
 * rules live in the actions. This controller only connects the two.
 */
class PurchaseOrderController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'supplier:id,code,name',
        'warehouse:id,code,name',
        'lines.product:id,code,name,primary_uom_id',
        'lines.product.primaryUom:id,code',
        'goodsReceipts:id,grn_number,purchase_order_id,receipt_date,status',
        'createdBy:id,name',
        'approvedBy:id,name',
        'cancelledBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = PurchaseOrder::query()
            ->with(['supplier:id,code,name', 'warehouse:id,code,name', 'lines'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('supplier_id'), fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereDate('order_date', '>=', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereDate('order_date', '<=', $d))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return PurchaseOrderResource::collection($orders);
    }

    public function show(PurchaseOrder $order): PurchaseOrderResource
    {
        return new PurchaseOrderResource($order->load(self::DETAIL));
    }

    public function store(StorePurchaseOrderRequest $request, SavePurchaseOrder $save): JsonResponse
    {
        $order = $save->handle(null, $request->validated(), $request->user()->id);

        return (new PurchaseOrderResource($order->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $order, SavePurchaseOrder $save): PurchaseOrderResource
    {
        return new PurchaseOrderResource($save->handle($order, $request->validated(), $request->user()->id)->load(self::DETAIL));
    }

    public function approve(Request $request, PurchaseOrder $order, ApprovePurchaseOrder $approve): PurchaseOrderResource
    {
        return new PurchaseOrderResource($approve->handle($order, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, PurchaseOrder $order, CancelPurchaseOrder $cancel): PurchaseOrderResource
    {
        return new PurchaseOrderResource($cancel->handle($order, $request->user()->id)->load(self::DETAIL));
    }
}
