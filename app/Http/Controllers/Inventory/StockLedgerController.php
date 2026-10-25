<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\InventoryTransactionType;
use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionLine;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only stock ledger.
 *
 * This screen shows posted movements and nothing else — there are deliberately no posting
 * controls. Inventory is written only through App\Actions\Inventory\PostInventoryTransaction,
 * called by business documents in later phases.
 */
class StockLedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'product_id' => $request->integer('product_id') ?: null,
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'lot_id' => $request->integer('lot_id') ?: null,
            'transaction_type' => $request->string('transaction_type')->toString() ?: null,
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
        ];

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
            ->when($filters['product_id'], fn (Builder $q, int $id) => $q->where('product_id', $id))
            ->when($filters['warehouse_id'], fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($filters['lot_id'], fn (Builder $q, int $id) => $q->where('lot_id', $id))
            ->when($filters['transaction_type'], fn (Builder $q, string $type) => $q->whereHas(
                'transaction',
                fn (Builder $t) => $t->where('transaction_type', $type)
            ))
            ->when($filters['from'], fn (Builder $q, string $from) => $q->whereHas(
                'transaction',
                fn (Builder $t) => $t->whereOnOrAfter('transaction_date', $from)
            ))
            ->when($filters['to'], fn (Builder $q, string $to) => $q->whereHas(
                'transaction',
                fn (Builder $t) => $t->whereOnOrBefore('transaction_date', $to)
            ));

        $list = ListQuery::from($request)->sortable([
            'date' => fn (Builder $q, string $d) => $q->orderBy(InventoryTransaction::select('transaction_date')->whereColumn('inventory_transactions.id', 'inventory_transaction_lines.inventory_transaction_id'), $d),
            'reference' => fn (Builder $q, string $d) => $q->orderBy(InventoryTransaction::select('reference')->whereColumn('inventory_transactions.id', 'inventory_transaction_lines.inventory_transaction_id'), $d),
            'type' => fn (Builder $q, string $d) => $q->orderBy(InventoryTransaction::select('transaction_type')->whereColumn('inventory_transactions.id', 'inventory_transaction_lines.inventory_transaction_id'), $d),
            'product' => fn (Builder $q, string $d) => $q->orderBy(Product::select('code')->whereColumn('products.id', 'inventory_transaction_lines.product_id'), $d),
            'warehouse' => fn (Builder $q, string $d) => $q->orderBy(Warehouse::select('code')->whereColumn('warehouses.id', 'inventory_transaction_lines.warehouse_id'), $d),
            'lot' => fn (Builder $q, string $d) => $q->orderBy(InventoryLot::select('lot_number')->whereColumn('inventory_lots.id', 'inventory_transaction_lines.lot_id'), $d),
            'unit_cost' => 'unit_cost',
            'total_cost' => 'total_cost',
        ], 'date');

        $lines = $list->paginate($lines)
            ->through(fn (InventoryTransactionLine $line): array => [
                'id' => $line->id,
                'date' => $line->transaction?->transaction_date?->toDateString(),
                'reference' => $line->transaction?->reference,
                'type' => $line->transaction?->transaction_type?->value,
                'type_label' => $line->transaction?->transaction_type?->label(),
                'is_reversal' => $line->transaction?->reversal_of_id !== null,
                'product_code' => $line->product?->code,
                'product_name' => $line->product?->name,
                'warehouse_code' => $line->warehouse?->code,
                'lot_number' => $line->lot?->lot_number,
                'direction' => $line->direction->value,
                'in_quantity' => $line->direction->isIn() ? $line->quantity : null,
                'out_quantity' => $line->direction->isOut() ? $line->quantity : null,
                'primary_uom' => $line->product?->primaryUom?->code,
                'secondary_quantity' => $line->secondary_quantity,
                'secondary_uom' => $line->product?->secondaryUom?->code,
                'unit_cost' => $line->unit_cost,
                'total_cost' => $line->total_cost,
                'user' => $line->transaction?->postedBy?->name,
            ]);

        return Inertia::render('inventory/StockLedger', [
            'lines' => $lines,
            // The period is the transaction's date, filtered above through the transaction.
            'filters' => $filters + $list->filters(),
            'products' => Product::query()->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->orderBy('code')->get(['id', 'code', 'name']),
            'lots' => InventoryLot::query()->orderByDesc('id')->limit(200)->get(['id', 'lot_number']),
            'transactionTypes' => InventoryTransactionType::options(),
        ]);
    }
}
