<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Payables\CancelSupplierPayment;
use App\Actions\Payables\PostSupplierPayment;
use App\Actions\Payables\ReverseSupplierPayment;
use App\Actions\Payables\SaveSupplierPayment;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payables\StoreSupplierPaymentRequest;
use App\Http\Resources\V1\Finance\SupplierPaymentResource;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Supplier payments over the API. No update endpoint — as on the website, a wrong draft is
 * cancelled and raised again.
 */
class SupplierPaymentController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'supplier:id,code,name',
        'paymentAccount:id,code,name',
        'allocations.invoice:id,internal_invoice_number,supplier_invoice_number',
        'journalEntry:id,journal_number',
        'reversalJournalEntry:id,journal_number',
        'createdBy:id,name',
        'postedBy:id,name',
        'reversedBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = SupplierPayment::query()
            ->with(['supplier:id,code,name', 'paymentAccount:id,code,name', 'allocations'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('supplier_id'), fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return SupplierPaymentResource::collection($payments);
    }

    public function show(SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        return new SupplierPaymentResource($supplierPayment->load(self::DETAIL));
    }

    public function store(StoreSupplierPaymentRequest $request, SaveSupplierPayment $save): JsonResponse
    {
        $payment = $save->handle($request->validated(), $request->user()->id);

        return (new SupplierPaymentResource($payment->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function post(Request $request, SupplierPayment $supplierPayment, PostSupplierPayment $post): SupplierPaymentResource
    {
        return new SupplierPaymentResource($post->handle($supplierPayment, $request->user()->id)->load(self::DETAIL));
    }

    public function reverse(Request $request, SupplierPayment $supplierPayment, ReverseSupplierPayment $reverse): SupplierPaymentResource
    {
        return new SupplierPaymentResource($reverse->handle($supplierPayment, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, SupplierPayment $supplierPayment, CancelSupplierPayment $cancel): SupplierPaymentResource
    {
        return new SupplierPaymentResource($cancel->handle($supplierPayment, $request->user()->id)->load(self::DETAIL));
    }
}
