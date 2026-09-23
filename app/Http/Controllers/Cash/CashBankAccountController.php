<?php

namespace App\Http\Controllers\Cash;

use App\Enums\AccountType;
use App\Enums\CashBankAccountKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\StoreCashBankAccountRequest;
use App\Http\Requests\Cash\UpdateCashBankAccountRequest;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Services\Accounting\CashBankBookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash and bank account configuration.
 *
 * The balance shown beside each account is **derived from posted journal lines every time
 * this page loads**. Nothing here edits a balance, and there is deliberately no way to: the
 * general ledger is the only record of how much money there is.
 *
 * There is no destroy route. A configuration money has moved through is deactivated, never
 * deleted, so historic documents keep referring to something meaningful.
 */
class CashBankAccountController extends Controller
{
    public function __construct(private readonly CashBankBookService $book) {}

    public function index(Request $request): Response
    {
        $showInactive = $request->boolean('show_inactive');

        return Inertia::render('finance/cash-bank-accounts/Index', [
            // Balances derived, never stored.
            'summary' => $this->book->summary(activeOnly: ! $showInactive),
            'filters' => ['show_inactive' => $showInactive],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('finance/cash-bank-accounts/Create', [
            'availableAccounts' => $this->availableAccounts(),
            'kinds' => CashBankAccountKind::options(),
        ]);
    }

    public function store(StoreCashBankAccountRequest $request): RedirectResponse
    {
        CashBankAccount::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cash/bank account configured.')]);

        return to_route('cash-bank-accounts.index');
    }

    public function edit(CashBankAccount $cashBankAccount): Response
    {
        $cashBankAccount->load('account:id,code,name');

        return Inertia::render('finance/cash-bank-accounts/Edit', [
            'cashBankAccount' => [
                'id' => $cashBankAccount->id,
                'account_code' => $cashBankAccount->account?->code,
                'account_name' => $cashBankAccount->account?->name,
                'account_kind' => $cashBankAccount->account_kind->value,
                'display_name' => $cashBankAccount->display_name,
                'bank_name' => $cashBankAccount->bank_name,
                'branch_name' => $cashBankAccount->branch_name,
                'account_number' => $cashBankAccount->account_number,
                'notes' => $cashBankAccount->notes,
                'is_active' => $cashBankAccount->is_active,
            ],
            'kinds' => CashBankAccountKind::options(),
        ]);
    }

    public function update(UpdateCashBankAccountRequest $request, CashBankAccount $cashBankAccount): RedirectResponse
    {
        // The underlying account is not in the validated data, so it cannot be repointed.
        $cashBankAccount->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cash/bank account updated.')]);

        return to_route('cash-bank-accounts.index');
    }

    /**
     * Accounts eligible to be configured: active assets, not control accounts, not headers,
     * and not already configured.
     *
     * @return list<array<string, mixed>>
     */
    private function availableAccounts(): array
    {
        return Account::query()
            ->where('is_active', true)
            ->where('account_type', AccountType::Asset)
            ->where('is_control_account', false)
            ->where('allow_manual_posting', true)
            ->whereNotIn('id', CashBankAccount::query()->pluck('account_id'))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->all();
    }
}
