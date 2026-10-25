<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Receivables\CancelCustomerReceipt;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Actions\Receivables\ReverseCustomerReceipt;
use App\Actions\Receivables\SaveCustomerReceipt;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receivables\StoreCustomerReceiptRequest;
use App\Http\Resources\V1\Finance\CustomerReceiptResource;
use App\Models\CustomerReceipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Customer receipts over the API. No update endpoint — as on the website, a wrong draft is
 * cancelled and raised again.
 */
class CustomerReceiptController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'customer:id,code,name',
        'receiptAccount:id,code,name',
        'allocations.invoice:id,invoice_number',
        'journalEntry:id,journal_number',
        'reversalJournalEntry:id,journal_number',
        'createdBy:id,name',
        'postedBy:id,name',
        'reversedBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $receipts = CustomerReceipt::query()
            ->with(['customer:id,code,name', 'receiptAccount:id,code,name', 'allocations'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('customer_id'), fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereOnOrAfter('receipt_date', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereOnOrBefore('receipt_date', $d))
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return CustomerReceiptResource::collection($receipts);
    }

    public function show(CustomerReceipt $customerReceipt): CustomerReceiptResource
    {
        return new CustomerReceiptResource($customerReceipt->load(self::DETAIL));
    }

    public function store(StoreCustomerReceiptRequest $request, SaveCustomerReceipt $save): JsonResponse
    {
        $receipt = $save->handle($request->validated(), $request->user()->id);

        return (new CustomerReceiptResource($receipt->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function post(Request $request, CustomerReceipt $customerReceipt, PostCustomerReceipt $post): CustomerReceiptResource
    {
        return new CustomerReceiptResource($post->handle($customerReceipt, $request->user()->id)->load(self::DETAIL));
    }

    public function reverse(Request $request, CustomerReceipt $customerReceipt, ReverseCustomerReceipt $reverse): CustomerReceiptResource
    {
        return new CustomerReceiptResource($reverse->handle($customerReceipt, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, CustomerReceipt $customerReceipt, CancelCustomerReceipt $cancel): CustomerReceiptResource
    {
        return new CustomerReceiptResource($cancel->handle($customerReceipt, $request->user()->id)->load(self::DETAIL));
    }
}
