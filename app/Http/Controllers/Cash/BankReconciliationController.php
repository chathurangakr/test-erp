<?php

namespace App\Http\Controllers\Cash;

use App\Actions\Cash\RecordBankReconciliation;
use App\Actions\Cash\ReverseBankReconciliation;
use App\Enums\CashBankAccountKind;
use App\Http\Controllers\Controller;
use App\Models\BankReconciliation;
use App\Models\CashBankAccount;
use App\Models\JournalEntryLine;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\BankReconciliationService;
use App\Support\BusinessClock;
use App\Support\SourceDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Bank reconciliation: pick a bank account, enter the statement's date and closing
 * balance, tick the lines the statement shows, and finish when nothing is left over. Earlier
 * statements are listed; the latest can be undone. See docs/ACCOUNTING_RULES.md §36.
 */
class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $service,
        private readonly AccountingBalanceService $balances,
    ) {}

    public function index(Request $request): Response
    {
        $accounts = CashBankAccount::query()->with('account:id,code,name')
            ->where('is_active', true)->where('account_kind', CashBankAccountKind::Bank->value)->get();
        $account = $accounts->firstWhere('id', $request->integer('cash_bank_account_id')) ?? $accounts->first();
        $statementDate = $request->date('statement_date') ?? BusinessClock::today();

        return Inertia::render('finance/bank-reconciliation/Index', [
            'accounts' => $accounts->map(fn (CashBankAccount $a): array => ['id' => $a->id, 'name' => $a->display_name])->values(),
            'account' => $account === null ? null : [
                'id' => $account->id,
                'name' => $account->display_name,
                'book_balance' => $this->balances->netBalance($account->account_id, Carbon::parse($statementDate->toDateString())),
                'reconciled_balance' => $this->service->reconciledBalance($account),
                'last_statement_date' => $this->service->latest($account)?->statement_date->toDateString(),
            ],
            'statementDate' => $statementDate->toDateString(),
            'lines' => $account === null ? [] : $this->service->unreconciledLines($account, $statementDate)->map(fn (JournalEntryLine $line): array => [
                'id' => $line->id,
                'date' => $line->entry->journal_date->toDateString(),
                'journal_number' => $line->entry->journal_number,
                'source' => SourceDocument::label($line->entry->source_type),
                'description' => $line->description ?: $line->entry->description,
                'money_in' => (string) $line->debit,
                'money_out' => (string) $line->credit,
            ])->values(),
            'history' => $account === null ? [] : BankReconciliation::query()
                ->where('cash_bank_account_id', $account->id)
                ->withCount('lines')
                ->with('createdBy:id,name')
                ->orderByDesc('statement_date')->orderByDesc('id')
                ->limit(24)->get()
                ->map(fn (BankReconciliation $r): array => [
                    'id' => $r->id,
                    'statement_date' => $r->statement_date->toDateString(),
                    'statement_balance' => (string) $r->statement_balance,
                    'lines' => $r->lines_count,
                    'status' => $r->status,
                    'by' => $r->createdBy?->name,
                    'can_undo' => $r->status === BankReconciliation::COMPLETED && $this->service->latest($account)?->is($r),
                ])->values(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(Request $request, RecordBankReconciliation $action): RedirectResponse
    {
        $data = $request->validate([
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            'statement_date' => ['required', 'date_format:Y-m-d'],
            'statement_balance' => ['required', 'numeric'],
            'journal_entry_line_ids' => ['array'],
            'journal_entry_line_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $action->handle($data, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Statement reconciled.')]);

        return to_route('bank-reconciliation.index', ['cash_bank_account_id' => $data['cash_bank_account_id']]);
    }

    public function reverse(BankReconciliation $bankReconciliation, ReverseBankReconciliation $action): RedirectResponse
    {
        $action->handle($bankReconciliation, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reconciliation undone. Its lines can be ticked again.')]);

        return to_route('bank-reconciliation.index', ['cash_bank_account_id' => $bankReconciliation->cash_bank_account_id]);
    }
}
