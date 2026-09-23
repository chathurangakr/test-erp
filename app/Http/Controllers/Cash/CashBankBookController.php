<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\CashBankAccount;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\CashBankBookService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cash book and the bank book — read only.
 *
 * Every row is a posted journal line touching the chosen account, whatever posted it. The
 * closing balance is checked against the account's general-ledger balance on every request:
 * they must agree exactly, and the page says so rather than leaving it to be believed.
 */
class CashBankBookController extends Controller
{
    public function __construct(
        private readonly CashBankBookService $book,
        private readonly AccountingBalanceService $balances,
    ) {}

    public function index(Request $request): Response
    {
        $accounts = CashBankAccount::query()
            ->with('account:id,code,name,is_active')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (CashBankAccount $configuration): string => (string) $configuration->account?->code)
            ->values();

        $selected = $accounts->firstWhere('id', $request->integer('cash_bank_account_id'))
            ?? $accounts->first();

        $from = $request->filled('from') ? Carbon::parse((string) $request->string('from')) : null;
        $to = $request->filled('to') ? Carbon::parse((string) $request->string('to')) : null;

        $report = $selected === null
            ? null
            : $this->book->book($selected, $from, $to);

        return Inertia::render('finance/cash-bank-book/Index', [
            'accounts' => $accounts->map(fn (CashBankAccount $configuration): array => [
                'id' => $configuration->id,
                'display_name' => $configuration->display_name,
                'account_code' => $configuration->account?->code,
                'kind_label' => $configuration->account_kind->label(),
            ])->all(),
            'selected' => $selected === null ? null : [
                'id' => $selected->id,
                'display_name' => $selected->display_name,
                'account_code' => $selected->account?->code,
                'account_name' => $selected->account?->name,
                'kind_label' => $selected->account_kind->label(),
            ],
            'report' => $report,
            // The whole point of the report: it must equal the general ledger, exactly.
            'ledger' => $selected === null ? null : [
                'balance' => $this->balances->netBalance($selected->account_id, $to),
                'reconciles' => $this->book->reconciles($selected, $to),
            ],
            'filters' => [
                'cash_bank_account_id' => $selected?->id,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
