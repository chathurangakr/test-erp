<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Actions\Purchasing\CancelGoodsReceipt;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Actions\Purchasing\SaveGoodsReceipt;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreGoodsReceiptRequest;
use App\Http\Requests\Purchasing\UpdateGoodsReceiptRequest;
use App\Http\Resources\V1\Purchasing\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Goods receipts over the API.
 *
 * Posting goes through PostGoodsReceipt — the same landed-cost allocation, the same inventory
 * engine, the same locks as the website. Posting twice is refused by the document's state,
 * so a retried request from a phone cannot receive stock twice; the second call gets 409.
 */
class GoodsReceiptController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'supplier:id,code,name',
        'warehouse:id,code,name',
        'purchaseOrder:id,po_number',
        'lines.product:id,code,name,primary_uom_id,secondary_uom_id',
        'lines.product.primaryUom:id,code',
        'lines.product.secondaryUom:id,code',
        'lines.lot:id,lot_number',
        'inventoryTransaction:id,reference',
        'reversalInventoryTransaction:id,reference',
        'createdBy:id,name',
        'postedBy:id,name',
        'reversedBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $receipts = GoodsReceipt::query()
            ->with(['supplier:id,code,name', 'warehouse:id,code,name', 'purchaseOrder:id,po_number'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('supplier_id'), fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->when($request->integer('warehouse_id'), fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereDate('receipt_date', '>=', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereDate('receipt_date', '<=', $d))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return GoodsReceiptResource::collection($receipts);
    }

    public function show(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        return new GoodsReceiptResource($goodsReceipt->load(self::DETAIL));
    }

    public function store(StoreGoodsReceiptRequest $request, SaveGoodsReceipt $save): JsonResponse
    {
        $receipt = $save->handle(null, $request->validated(), $request->user()->id);

        return (new GoodsReceiptResource($receipt->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt, SaveGoodsReceipt $save): GoodsReceiptResource
    {
        return new GoodsReceiptResource($save->handle($goodsReceipt, $request->validated(), $request->user()->id)->load(self::DETAIL));
    }

    public function post(Request $request, GoodsReceipt $goodsReceipt, PostGoodsReceipt $post): GoodsReceiptResource
    {
        return new GoodsReceiptResource($post->handle($goodsReceipt, $request->user()->id)->load(self::DETAIL));
    }

    public function reverse(Request $request, GoodsReceipt $goodsReceipt, ReverseGoodsReceipt $reverse): GoodsReceiptResource
    {
        return new GoodsReceiptResource($reverse->handle($goodsReceipt, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, GoodsReceipt $goodsReceipt, CancelGoodsReceipt $cancel): GoodsReceiptResource
    {
        return new GoodsReceiptResource($cancel->handle($goodsReceipt, $request->user()->id)->load(self::DETAIL));
    }
}
