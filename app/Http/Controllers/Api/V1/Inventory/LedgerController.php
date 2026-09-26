<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Inventory\LedgerLineResource;
use App\Models\InventoryTransactionLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The inventory ledger — read only and always paginated, with the web Stock Ledger's filters:
 * product, warehouse, lot, transaction type and date range. Posted ledger lines are immutable.
 */
class LedgerController extends Controller
{
    use PaginatesResults;

    public function index(Request $request): AnonymousResourceCollection
    {
        $lines = InventoryTransactionLine::query()
            ->with([
                'transaction:id,reference,transaction_type,transaction_date,posted_by,reversal_of_id',
                'transaction.postedBy:id,name',
                'product:id,code,name,primary_uom_id,secondary_uom_id',
                'product.primaryUom:id,code',
                'product.secondaryUom:id,code',
                'warehouse:id,code,name',
                'lot:id,lot_number',
            ])
            ->when($request->integer('product_id'), fn (Builder $q, int $id) => $q->where('product_id', $id))
            ->when($request->integer('warehouse_id'), fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($request->integer('lot_id'), fn (Builder $q, int $id) => $q->where('lot_id', $id))
            ->when($request->string('transaction_type')->toString(), fn (Builder $q, string $type) => $q->whereHas(
                'transaction',
                fn (Builder $t) => $t->where('transaction_type', $type),
            ))
            ->when($request->date('from')?->toDateString(), fn (Builder $q, string $from) => $q->whereHas(
                'transaction',
                fn (Builder $t) => $t->whereDate('transaction_date', '>=', $from),
            ))
            ->when($request->date('to')?->toDateString(), fn (Builder $q, string $to) => $q->whereHas(
                'transaction',
                fn (Builder $t) => $t->whereDate('transaction_date', '<=', $to),
            ))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return LedgerLineResource::collection($lines);
    }
}
