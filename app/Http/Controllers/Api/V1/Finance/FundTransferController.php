<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Cash\CancelFundTransfer;
use App\Actions\Cash\PostFundTransfer;
use App\Actions\Cash\ReverseFundTransfer;
use App\Actions\Cash\SaveFundTransfer;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\StoreFundTransferRequest;
use App\Http\Resources\V1\Finance\FundTransferResource;
use App\Models\FundTransfer;
use App\Services\Accounting\CashDocumentOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Fund transfers over the API. There is no update endpoint, exactly as on the website: a wrong
 * draft is cancelled and replaced, a wrong posted transfer is reversed.
 */
class FundTransferController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'fromAccount.account:id,code,name',
        'toAccount.account:id,code,name',
        'journalEntry.lines.account:id,code,name',
        'reversalJournalEntry:id,journal_number',
        'createdBy:id,name',
        'postedBy:id,name',
        'reversedBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $transfers = FundTransfer::query()
            ->with(['fromAccount:id,display_name', 'toAccount:id,display_name'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereOnOrAfter('transfer_date', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereOnOrBefore('transfer_date', $d))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return FundTransferResource::collection($transfers);
    }

    /**
     * The configured cash and bank accounts a transfer may move money between.
     */
    public function options(CashDocumentOptions $options): JsonResponse
    {
        return response()->json(['data' => ['cash_bank_accounts' => $options->cashBankAccounts()]]);
    }

    public function show(FundTransfer $fundTransfer): FundTransferResource
    {
        return new FundTransferResource($fundTransfer->load(self::DETAIL));
    }

    public function store(StoreFundTransferRequest $request, SaveFundTransfer $save): JsonResponse
    {
        $transfer = $save->handle($request->validated(), $request->user()->id);

        return (new FundTransferResource($transfer->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function post(Request $request, FundTransfer $fundTransfer, PostFundTransfer $post): FundTransferResource
    {
        return new FundTransferResource($post->handle($fundTransfer, $request->user()->id)->load(self::DETAIL));
    }

    public function reverse(Request $request, FundTransfer $fundTransfer, ReverseFundTransfer $reverse): FundTransferResource
    {
        return new FundTransferResource($reverse->handle($fundTransfer, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, FundTransfer $fundTransfer, CancelFundTransfer $cancel): FundTransferResource
    {
        return new FundTransferResource($cancel->handle($fundTransfer, $request->user()->id)->load(self::DETAIL));
    }
}
