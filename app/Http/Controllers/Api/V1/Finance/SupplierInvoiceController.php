<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Payables\CancelSupplierInvoice;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Payables\ReverseSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payables\StoreSupplierInvoiceRequest;
use App\Http\Requests\Payables\UpdateSupplierInvoiceRequest;
use App\Http\Resources\V1\Finance\SupplierInvoiceResource;
use App\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Supplier invoices over the API — the same actions and validation as the website.
 *
 * Posting creates the payable and clears GRNI through PostSupplierInvoice, with its caps and
 * locks; a second post of the same invoice is refused by its state (409).
 */
class SupplierInvoiceController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'supplier:id,code,name',
        'lines.goodsReceipt:id,grn_number',
        'lines.debitAccount:id,code,name',
        'journalEntry:id,journal_number',
        'reversalJournalEntry:id,journal_number',
        'createdBy:id,name',
        'postedBy:id,name',
        'reversedBy:id,name',
        'allocations.payment:id,payment_number,payment_date,status',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = SupplierInvoice::query()
            ->with(['supplier:id,code,name'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('supplier_id'), fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereOnOrAfter('invoice_date', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereOnOrBefore('invoice_date', $d))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return SupplierInvoiceResource::collection($invoices);
    }

    public function show(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        return new SupplierInvoiceResource($supplierInvoice->load(self::DETAIL));
    }

    public function store(StoreSupplierInvoiceRequest $request, SaveSupplierInvoice $save): JsonResponse
    {
        $invoice = $save->handle(null, $request->validated(), $request->user()->id);

        return (new SupplierInvoiceResource($invoice->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(UpdateSupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice, SaveSupplierInvoice $save): SupplierInvoiceResource
    {
        return new SupplierInvoiceResource($save->handle($supplierInvoice, $request->validated(), $request->user()->id)->load(self::DETAIL));
    }

    public function post(Request $request, SupplierInvoice $supplierInvoice, PostSupplierInvoice $post): SupplierInvoiceResource
    {
        return new SupplierInvoiceResource($post->handle($supplierInvoice, $request->user()->id)->load(self::DETAIL));
    }

    public function reverse(Request $request, SupplierInvoice $supplierInvoice, ReverseSupplierInvoice $reverse): SupplierInvoiceResource
    {
        return new SupplierInvoiceResource($reverse->handle($supplierInvoice, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, SupplierInvoice $supplierInvoice, CancelSupplierInvoice $cancel): SupplierInvoiceResource
    {
        return new SupplierInvoiceResource($cancel->handle($supplierInvoice, $request->user()->id)->load(self::DETAIL));
    }
}
