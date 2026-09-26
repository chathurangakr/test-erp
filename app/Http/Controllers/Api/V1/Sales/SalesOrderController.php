<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Actions\Sales\ApproveSalesOrder;
use App\Actions\Sales\CancelSalesOrder;
use App\Actions\Sales\SaveSalesOrder;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesOrderRequest;
use App\Http\Requests\Sales\UpdateSalesOrderRequest;
use App\Http\Resources\V1\Sales\SalesOrderResource;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sales orders over the API — the same actions and validation as the website.
 *
 * Authorization is declared on each route (routes/api/v1/sales.php); business-state rules
 * live in the actions. This controller only connects the two.
 */
class SalesOrderController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'customer:id,code,name',
        'warehouse:id,code,name',
        'lines.product:id,code,name,primary_uom_id,secondary_uom_id',
        'lines.product.primaryUom:id,code',
        'lines.product.secondaryUom:id,code',
        'deliveries:id,delivery_number,sales_order_id,delivery_date,status',
        'createdBy:id,name',
        'approvedBy:id,name',
        'cancelledBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = SalesOrder::query()
            ->with(['customer:id,code,name', 'warehouse:id,code,name', 'lines'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('customer_id'), fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereDate('order_date', '>=', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereDate('order_date', '<=', $d))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return SalesOrderResource::collection($orders);
    }

    public function show(SalesOrder $order): SalesOrderResource
    {
        return new SalesOrderResource($order->load(self::DETAIL));
    }

    public function store(StoreSalesOrderRequest $request, SaveSalesOrder $save): JsonResponse
    {
        $order = $save->handle(null, $request->validated(), $request->user()->id);

        return (new SalesOrderResource($order->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(UpdateSalesOrderRequest $request, SalesOrder $order, SaveSalesOrder $save): SalesOrderResource
    {
        return new SalesOrderResource($save->handle($order, $request->validated(), $request->user()->id)->load(self::DETAIL));
    }

    public function approve(Request $request, SalesOrder $order, ApproveSalesOrder $approve): SalesOrderResource
    {
        return new SalesOrderResource($approve->handle($order, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, SalesOrder $order, CancelSalesOrder $cancel): SalesOrderResource
    {
        return new SalesOrderResource($cancel->handle($order, $request->user()->id)->load(self::DETAIL));
    }
}
