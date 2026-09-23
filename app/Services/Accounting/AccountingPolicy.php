<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Whether a stock movement reaches the general ledger, and which accounts it touches.
 *
 * Everything the operational modules need to know about accounting is asked here, so no
 * account code appears in a posting action and the cutover rule exists in exactly one place.
 *
 * Three questions:
 *
 *   1. **Does accounting apply to this date?** Only when a cutover date is configured and
 *      the document falls on or after it. Before that — and while no cutover is set at all —
 *      the operational modules behave exactly as they did before Finance existed.
 *   2. **Which account does this product use?** From `product_account_mappings`. An unmapped
 *      product is REFUSED, never guessed at.
 *   3. **Which period does the journal belong to?** An open one containing the document's
 *      date, or the whole operational posting fails.
 *
 * See docs/ACCOUNTING_RULES.md §13.
 */
class AccountingPolicy
{
    /**
     * Whether a document dated `$date` must generate a journal.
     */
    public function appliesTo(CarbonInterface|string $date): bool
    {
        $settings = AccountingSetting::current();

        if ($settings === null || ! $settings->isOperationalAccountingEnabled()) {
            return false;
        }

        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return ! $date->startOfDay()->lessThan($settings->operational_accounting_start_date->startOfDay());
    }

    /**
     * The open fiscal period a journal for this document belongs to.
     *
     * A document that requires accounting but has no open period cannot be posted at all —
     * posting stock while its journal silently went missing would put the ledger and the
     * books permanently out of step.
     */
    public function periodFor(CarbonInterface|string $date, string $document): FiscalPeriod
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        $period = FiscalPeriod::openFor($date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'journal_date' => __('There is no open fiscal period covering :date, so :document cannot be posted. Open the period first.', [
                    'date' => $date->toDateString(),
                    'document' => $document,
                ]),
            ]);
        }

        return $period;
    }

    /**
     * The inventory asset account a product's stock sits in.
     */
    public function inventoryAccountFor(Product $product): Account
    {
        return $this->mappedAccount($product, 'inventory', AccountType::Asset);
    }

    /**
     * The cost-of-sales account a product's issues are charged to.
     */
    public function cogsAccountFor(Product $product): Account
    {
        return $this->mappedAccount($product, 'cogs', AccountType::CostOfSales);
    }

    /**
     * Goods Received Not Invoiced — the liability a receipt creates before the supplier's
     * invoice is recorded.
     */
    public function grniAccount(): Account
    {
        $account = AccountingSetting::current()?->grniAccount;

        if ($account === null) {
            throw ValidationException::withMessages([
                'grni_account_id' => __('No Goods Received Not Invoiced account is configured. Set one in Accounting Setup before receiving stock.'),
            ]);
        }

        $this->assertUsable($account, AccountType::Liability, __('Goods Received Not Invoiced'));

        return $account;
    }

    /**
     * Trade Payables — what the factory owes suppliers, once their invoices are recorded.
     *
     * A control account, so only a sourced (system) journal may reach it.
     */
    public function tradePayablesAccount(): Account
    {
        $account = AccountingSetting::current()?->tradePayablesAccount;

        if ($account === null) {
            throw ValidationException::withMessages([
                'trade_payables_account_id' => __('No Trade Payables account is configured. Set one in Accounting Setup before recording supplier invoices.'),
            ]);
        }

        $this->assertUsable($account, AccountType::Liability, __('Trade Payables'));

        return $account;
    }

    /**
     * Trade Receivables — what customers owe once their invoices are recognised.
     *
     * A control account, so only a sourced (system) journal may reach it.
     */
    public function tradeReceivablesAccount(): Account
    {
        $account = AccountingSetting::current()?->tradeReceivablesAccount;

        if ($account === null) {
            throw ValidationException::withMessages([
                'trade_receivables_account_id' => __('No Trade Receivables account is configured. Set one in Accounting Setup before invoicing customers.'),
            ]);
        }

        $this->assertUsable($account, AccountType::Asset, __('Trade Receivables'));

        return $account;
    }

    /**
     * The revenue account a product's sales are recognised in.
     */
    public function revenueAccountFor(Product $product): Account
    {
        return $this->mappedAccount($product, 'revenue', AccountType::Revenue);
    }

    /**
     * A cash or bank account a customer receipt may be banked into.
     *
     * Being an asset account is no longer enough: it must be one the factory has actually
     * configured as cash or a bank account. See assertCashBankAccount().
     */
    public function assertReceiptAccount(Account $account): void
    {
        $this->assertCashBankAccount($account, 'receipt_account_id', __('a customer receipt'));
    }

    /**
     * An account a supplier invoice may charge a direct expense to.
     *
     * Restricted to ordinary expense accounts: a direct-expense line must never be a way to
     * reach an inventory or payables control account by hand, which would let someone move
     * inventory value without a stock movement behind it.
     */
    public function assertDirectExpenseAccount(Account $account): void
    {
        $this->assertUsable($account, AccountType::Expense, __('a direct expense line'));

        if ($account->is_control_account) {
            throw ValidationException::withMessages([
                'account_id' => __('Account :account is a control account and cannot be charged directly on a supplier invoice.', [
                    'account' => $account->code,
                ]),
            ]);
        }

        if (! $account->allow_manual_posting) {
            throw ValidationException::withMessages([
                'account_id' => __('Account :account does not accept postings of this kind.', [
                    'account' => $account->code,
                ]),
            ]);
        }
    }

    /**
     * A cash or bank account a supplier payment may be made from.
     *
     * Being an asset account is no longer enough: it must be one the factory has actually
     * configured as cash or a bank account. See assertCashBankAccount().
     */
    public function assertPaymentAccount(Account $account): void
    {
        $this->assertCashBankAccount($account, 'payment_account_id', __('a supplier payment'));
    }

    /**
     * The one rule for "may money move through this account".
     *
     * Every module that moves money asks this: supplier payments, customer receipts, expense
     * vouchers and fund transfers. Two conditions, and both matter.
     *
     * The **account** must be active, an ASSET, not a control account, and hand-postable —
     * which between them exclude group headers, Trade Receivables, Trade Payables, GRNI and
     * every inventory control account, without this method naming a single account code.
     *
     * The **configuration** must exist and be active. That is the stricter half: an ordinary
     * asset account like "Other Current Assets" passes every test above and is still not
     * somewhere cash may be paid into or out of. Requiring a `cash_bank_accounts` row means
     * an accountant decides which accounts are real tills and real bank accounts, rather than
     * the shape of the chart deciding it by accident.
     */
    public function assertCashBankAccount(Account $account, string $field = 'cash_bank_account_id', ?string $subject = null): CashBankAccount
    {
        $subject ??= __('a cash or bank movement');

        $this->assertUsable($account, AccountType::Asset, $subject);

        if ($account->is_control_account) {
            throw ValidationException::withMessages([
                $field => __('Account :account is a control account and money cannot be moved through it directly.', [
                    'account' => $account->code,
                ]),
            ]);
        }

        if (! $account->allow_manual_posting) {
            throw ValidationException::withMessages([
                $field => __('Account :account does not accept postings of this kind.', [
                    'account' => $account->code,
                ]),
            ]);
        }

        $configuration = CashBankAccount::query()->where('account_id', $account->id)->first();

        if ($configuration === null) {
            throw ValidationException::withMessages([
                $field => __('Account :account is not configured as a cash or bank account. Configure it under Cash & Bank before using it for :subject.', [
                    'account' => $account->code,
                    'subject' => $subject,
                ]),
            ]);
        }

        if (! $configuration->is_active) {
            throw ValidationException::withMessages([
                $field => __('Cash/bank account :name is inactive and cannot be used for :subject.', [
                    'name' => $configuration->display_name,
                    'subject' => $subject,
                ]),
            ]);
        }

        return $configuration;
    }

    /**
     * The configured cash or bank account behind a configuration row, checked end to end.
     */
    public function assertUsableCashBankAccount(CashBankAccount $configuration, string $field = 'cash_bank_account_id', ?string $subject = null): CashBankAccount
    {
        $account = $configuration->account;

        if ($account === null) {
            throw ValidationException::withMessages([
                $field => __('Cash/bank account :name has no general-ledger account.', [
                    'name' => $configuration->display_name,
                ]),
            ]);
        }

        return $this->assertCashBankAccount($account, $field, $subject);
    }

    /**
     * An account an expense voucher may charge.
     *
     * Ordinary EXPENSE accounts only. COST_OF_SALES is deliberately excluded: it is derived
     * from stock movements by the inventory integration, and letting someone charge it by
     * hand would put cost of sales and the inventory ledger out of step with nothing to
     * explain the difference. Revenue, equity, receivables, payables, GRNI, inventory and the
     * cash accounts themselves are excluded by the type check alone.
     */
    public function assertExpenseAccount(Account $account, string $field = 'expense_account_id'): void
    {
        $this->assertUsable($account, AccountType::Expense, __('an expense voucher line'));

        if ($account->is_control_account) {
            throw ValidationException::withMessages([
                $field => __('Account :account is a control account and cannot be charged directly.', [
                    'account' => $account->code,
                ]),
            ]);
        }

        if (! $account->allow_manual_posting) {
            throw ValidationException::withMessages([
                $field => __('Account :account does not accept postings of this kind.', [
                    'account' => $account->code,
                ]),
            ]);
        }
    }

    private function mappedAccount(Product $product, string $kind, AccountType $expected): Account
    {
        $mapping = $product->accountMapping;

        if ($mapping === null) {
            throw ValidationException::withMessages([
                'product_id' => __('Product :product has no accounting mapping. Configure it in Accounting Setup before posting its stock.', [
                    'product' => $product->code,
                ]),
            ]);
        }

        $account = match ($kind) {
            'inventory' => $mapping->inventoryAccount,
            'revenue' => $mapping->revenueAccount,
            default => $mapping->cogsAccount,
        };

        if ($account === null) {
            throw ValidationException::withMessages([
                'product_id' => __('Product :product has no :kind account configured.', [
                    'product' => $product->code,
                    'kind' => $kind,
                ]),
            ]);
        }

        $this->assertUsable($account, $expected, $product->code);

        return $account;
    }

    /**
     * An account may be a control account — the system is allowed to post to those — but it
     * must exist, be active, and be the right class of account.
     */
    private function assertUsable(Account $account, AccountType $expected, string $subject): void
    {
        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account_id' => __('Account :account is inactive and cannot be posted to (:subject).', [
                    'account' => $account->code,
                    'subject' => $subject,
                ]),
            ]);
        }

        if ($account->account_type !== $expected) {
            throw ValidationException::withMessages([
                'account_id' => __('Account :account is a :actual account; :subject requires a :expected account.', [
                    'account' => $account->code,
                    'actual' => $account->account_type->label(),
                    'subject' => $subject,
                    'expected' => $expected->label(),
                ]),
            ]);
        }
    }
}
