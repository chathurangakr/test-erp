<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Receivables\CancelSalesInvoice;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Receivables\ReverseSalesInvoice;
use App\Actions\Receivables\SaveSalesInvoice;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receivables\StoreSalesInvoiceRequest;
use App\Http\Requests\Receivables\UpdateSalesInvoiceRequest;
use App\Http\Resources\V1\Finance\SalesInvoiceResource;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sales invoices over the API — the same actions and validation as the website.
 *
 * Posting recognises revenue through PostSalesInvoice: the revenue account comes from the
 * product mapping (no request field chooses one) and billing is capped at each delivery
 * line's remaining sales value under a row lock.
 */
class SalesInvoiceController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'customer:id,code,name',
        'lines.product:id,code,name',
        'lines.deliveryLine.delivery:id,delivery_number',
        'journalEntry:id,journal_number',
        'reversalJournalEntry:id,journal_number',
        'createdBy:id,name',
        'postedBy:id,name',
        'reversedBy:id,name',
        'allocations.receipt:id,receipt_number,receipt_date,status',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = SalesInvoice::query()
            ->with(['customer:id,code,name'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('customer_id'), fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return SalesInvoiceResource::collection($invoices);
    }

    public function show(SalesInvoice $salesInvoice): SalesInvoiceResource
    {
        return new SalesInvoiceResource($salesInvoice->load(self::DETAIL));
    }

    public function store(StoreSalesInvoiceRequest $request, SaveSalesInvoice $save): JsonResponse
    {
        $invoice = $save->handle(null, $request->validated(), $request->user()->id);

        return (new SalesInvoiceResource($invoice->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(UpdateSalesInvoiceRequest $request, SalesInvoice $salesInvoice, SaveSalesInvoice $save): SalesInvoiceResource
    {
        return new SalesInvoiceResource($save->handle($salesInvoice, $request->validated(), $request->user()->id)->load(self::DETAIL));
    }

    public function post(Request $request, SalesInvoice $salesInvoice, PostSalesInvoice $post): SalesInvoiceResource
    {
        return new SalesInvoiceResource($post->handle($salesInvoice, $request->user()->id)->load(self::DETAIL));
    }

    public function reverse(Request $request, SalesInvoice $salesInvoice, ReverseSalesInvoice $reverse): SalesInvoiceResource
    {
        return new SalesInvoiceResource($reverse->handle($salesInvoice, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, SalesInvoice $salesInvoice, CancelSalesInvoice $cancel): SalesInvoiceResource
    {
        return new SalesInvoiceResource($cancel->handle($salesInvoice, $request->user()->id)->load(self::DETAIL));
    }
}
