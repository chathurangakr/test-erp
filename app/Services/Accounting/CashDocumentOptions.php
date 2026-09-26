<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CashBankAccount;

/**
 * The choices a cash document form offers — shared by the web screens and the API so both
 * offer exactly the same accounts.
 */
class CashDocumentOptions
{
    /**
     * Active, configured cash and bank accounts whose ledger account is also active.
     *
     * @return list<array{id: int, code: string|null, name: string, kind: string}>
     */
    public function cashBankAccounts(): array
    {
        return CashBankAccount::query()
            ->with('account:id,code,name,is_active')
            ->where('is_active', true)
            ->get()
            ->filter(fn (CashBankAccount $configuration): bool => $configuration->account?->is_active === true)
            ->sortBy(fn (CashBankAccount $configuration): string => (string) $configuration->account?->code)
            ->map(fn (CashBankAccount $configuration): array => [
                'id' => $configuration->id,
                'code' => $configuration->account?->code,
                'name' => $configuration->display_name,
                'kind' => $configuration->account_kind->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * Ordinary operating expense accounts only.
     *
     * Cost of sales is absent on purpose: it is derived from stock movements, and charging it
     * by hand would put it out of step with the inventory ledger.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function expenseAccounts(): array
    {
        return Account::query()
            ->where('is_active', true)
            ->where('account_type', AccountType::Expense)
            ->where('is_control_account', false)
            ->where('allow_manual_posting', true)
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
