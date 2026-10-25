<?php

namespace App\Http\Controllers\Cash;

use App\Actions\Cash\SaveFundTransfer;
use App\Enums\FundTransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\StoreFundTransferRequest;
use App\Models\CashBankAccount;
use App\Models\FundTransfer;
use App\Services\Accounting\CashDocumentOptions;
use App\Support\BusinessClock;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fund transfers between the factory's own cash and bank accounts.
 *
 * There is no edit screen: a transfer is three facts — from, to, how much — so an incorrect
 * draft is cancelled and replaced, and an incorrect posted one is reversed. There is no
 * destroy route.
 */
class FundTransferController extends Controller
{
    public function __construct(
        private readonly SaveFundTransfer $saveTransfer,
        private readonly CashDocumentOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');

        $list = ListQuery::from($request)->period('transfer_date')->sortable([
            'number' => 'transfer_number',
            'date' => 'transfer_date',
            'from' => fn (Builder $q, string $d) => $q->orderBy(CashBankAccount::select('display_name')->whereColumn('cash_bank_accounts.id', 'fund_transfers.from_cash_bank_account_id'), $d),
            'to' => fn (Builder $q, string $d) => $q->orderBy(CashBankAccount::select('display_name')->whereColumn('cash_bank_accounts.id', 'fund_transfers.to_cash_bank_account_id'), $d),
            'status' => 'status',
            'amount' => 'amount',
        ], 'date');

        $transfers = $list->paginate(FundTransfer::query()
            ->with(['fromAccount:id,display_name', 'toAccount:id,display_name'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s)))
            ->through(fn (FundTransfer $transfer): array => $this->summarise($transfer));

        return Inertia::render('finance/fund-transfers/Index', [
            'transfers' => $transfers,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
            ] + $list->filters(),
            'statuses' => FundTransferStatus::options(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('finance/fund-transfers/Create', [
            'accounts' => $this->options->cashBankAccounts(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreFundTransferRequest $request): RedirectResponse
    {
        $transfer = $this->saveTransfer->handle($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fund transfer saved as draft.')]);

        return to_route('fund-transfers.show', $transfer);
    }

    public function show(FundTransfer $fundTransfer): Response
    {
        $fundTransfer->load([
            'fromAccount.account:id,code,name',
            'toAccount.account:id,code,name',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'postedBy:id,name',
            'reversedBy:id,name',
        ]);

        return Inertia::render('finance/fund-transfers/Show', [
            'transfer' => $this->detail($fundTransfer),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(FundTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'transfer_date' => $transfer->transfer_date?->toDateString(),
            'from_name' => $transfer->fromAccount?->display_name,
            'to_name' => $transfer->toAccount?->display_name,
            'amount' => $transfer->amount,
            'reference' => $transfer->reference,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(FundTransfer $transfer): array
    {
        return array_merge($this->summarise($transfer), [
            'description' => $transfer->description,
            'from_code' => $transfer->fromAccount?->account?->code,
            'to_code' => $transfer->toAccount?->account?->code,
            'created_by' => $transfer->createdBy?->name,
            'posted_by' => $transfer->postedBy?->name,
            'posted_at' => BusinessClock::display($transfer->posted_at),
            'reversed_by' => $transfer->reversedBy?->name,
            'reversed_at' => BusinessClock::display($transfer->reversed_at),
            'can_post' => $transfer->status->canPost(),
            'can_cancel' => $transfer->status->canCancel(),
            'can_reverse' => $transfer->status->canReverse() && $transfer->reversal_journal_entry_id === null,
            'journal' => $transfer->journalEntry === null ? null : [
                'id' => $transfer->journalEntry->id,
                'journal_number' => $transfer->journalEntry->journal_number,
                'lines' => $transfer->journalEntry->lines->map(fn ($line): array => [
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ])->values(),
            ],
            'reversal_journal_number' => $transfer->reversalJournalEntry?->journal_number,
        ]);
    }
}
