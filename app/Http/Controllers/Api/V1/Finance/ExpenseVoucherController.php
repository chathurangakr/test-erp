<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Cash\CancelExpenseVoucher;
use App\Actions\Cash\PostExpenseVoucher;
use App\Actions\Cash\ReverseExpenseVoucher;
use App\Actions\Cash\SaveExpenseVoucher;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\StoreExpenseVoucherRequest;
use App\Http\Requests\Cash\UpdateExpenseVoucherRequest;
use App\Http\Resources\V1\Finance\ExpenseVoucherResource;
use App\Models\ExpenseVoucher;
use App\Models\Supplier;
use App\Services\Accounting\CashDocumentOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Expense vouchers over the API — the same actions and validation as the website.
 *
 * Posting writes `Dr expense / Cr cash or bank` through PostExpenseVoucher; a second post of
 * the same voucher is refused by its state and answered 409.
 */
class ExpenseVoucherController extends Controller
{
    use PaginatesResults;

    private const DETAIL = [
        'lines.expenseAccount:id,code,name',
        'cashBankAccount.account:id,code,name',
        'supplier:id,code,name',
        'journalEntry.lines.account:id,code,name',
        'reversalJournalEntry:id,journal_number',
        'createdBy:id,name',
        'postedBy:id,name',
        'reversedBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $vouchers = ExpenseVoucher::query()
            ->with(['cashBankAccount:id,display_name', 'supplier:id,code,name', 'lines'])
            ->search((string) $request->string('search'))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->integer('cash_bank_account_id'), fn (Builder $q, int $id) => $q->where('cash_bank_account_id', $id))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereOnOrAfter('voucher_date', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereOnOrBefore('voucher_date', $d))
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ExpenseVoucherResource::collection($vouchers);
    }

    /**
     * What a new voucher may use: configured cash/bank accounts, ordinary expense accounts
     * (never cost of sales) and active suppliers — exactly what the web form offers.
     */
    public function options(CashDocumentOptions $options): JsonResponse
    {
        return response()->json(['data' => [
            'cash_bank_accounts' => $options->cashBankAccounts(),
            'expense_accounts' => $options->expenseAccounts(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]]);
    }

    public function show(ExpenseVoucher $expenseVoucher): ExpenseVoucherResource
    {
        return new ExpenseVoucherResource($expenseVoucher->load(self::DETAIL));
    }

    public function store(StoreExpenseVoucherRequest $request, SaveExpenseVoucher $save): JsonResponse
    {
        $voucher = $save->handle(null, $request->validated(), $request->user()->id);

        return (new ExpenseVoucherResource($voucher->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenseVoucherRequest $request, ExpenseVoucher $expenseVoucher, SaveExpenseVoucher $save): ExpenseVoucherResource
    {
        return new ExpenseVoucherResource($save->handle($expenseVoucher, $request->validated(), $request->user()->id)->load(self::DETAIL));
    }

    public function post(Request $request, ExpenseVoucher $expenseVoucher, PostExpenseVoucher $post): ExpenseVoucherResource
    {
        return new ExpenseVoucherResource($post->handle($expenseVoucher, $request->user()->id)->load(self::DETAIL));
    }

    public function reverse(Request $request, ExpenseVoucher $expenseVoucher, ReverseExpenseVoucher $reverse): ExpenseVoucherResource
    {
        return new ExpenseVoucherResource($reverse->handle($expenseVoucher, $request->user()->id)->load(self::DETAIL));
    }

    public function cancel(Request $request, ExpenseVoucher $expenseVoucher, CancelExpenseVoucher $cancel): ExpenseVoucherResource
    {
        return new ExpenseVoucherResource($cancel->handle($expenseVoucher, $request->user()->id)->load(self::DETAIL));
    }
}
