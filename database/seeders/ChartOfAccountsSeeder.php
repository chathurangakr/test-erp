<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The initial chart of accounts for the coconut factory.
 *
 * Deliberately minimal and entirely configurable: it is a starting point the accountant is
 * expected to extend, not a structure the code depends on. **No business logic resolves an
 * account by its code** — when purchasing, sales and production start generating journals,
 * they will do so through an explicit account mapping, so a factory that renumbers its chart
 * does not break the software.
 *
 * Two kinds of account refuse manual posting:
 *
 *  - **group headers** (1000, 1100, 1300…), which exist to organise the chart. Posting to a
 *    header and to its children would double-count in any report that rolls them up.
 *  - **control accounts** (receivables, payables, GRNI, inventory), which are reconciled
 *    from a subsidiary ledger. A hand-written line would put the control account and its
 *    subsidiary out of step with nothing to explain the difference. The system actions that
 *    own those subsidiary ledgers post to them instead.
 *
 * Idempotent: re-running updates the definition without disturbing posted history.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * code, name, type, is-header, is-control.
     *
     * Children follow their parent; the parent is resolved by the nearest preceding header.
     *
     * @var list<array{0: string, 1: string, 2: AccountType, 3: bool, 4: bool}>
     */
    private const ACCOUNTS = [
        // ---------------------------------------------------------------- assets
        ['1000', 'Assets', AccountType::Asset, true, false],
        ['1100', 'Cash and Cash Equivalents', AccountType::Asset, true, false],
        ['1110', 'Cash on Hand', AccountType::Asset, false, false],
        ['1120', 'Bank', AccountType::Asset, false, false],

        ['1200', 'Accounts Receivable', AccountType::Asset, true, false],
        ['1210', 'Trade Receivables', AccountType::Asset, false, true],
        // Salary advances employees owe back until a payroll takes them. Payroll's to post to.
        ['1220', 'Employee Advances', AccountType::Asset, false, true],

        ['1300', 'Inventory', AccountType::Asset, true, false],
        ['1310', 'Whole Coconut Inventory', AccountType::Asset, false, true],
        ['1320', 'Dehusked Coconut Inventory', AccountType::Asset, false, true],
        ['1330', 'Raw Husk Inventory', AccountType::Asset, false, true],
        ['1340', 'Husk Chips Inventory', AccountType::Asset, false, true],
        ['1350', 'Copra Inventory', AccountType::Asset, false, true],
        ['1360', 'Raw Shell Inventory', AccountType::Asset, false, true],
        ['1370', 'Cocopeat Inventory', AccountType::Asset, false, true],
        ['1380', 'Packing Materials Inventory', AccountType::Asset, false, true],

        ['1400', 'Other Current Assets', AccountType::Asset, false, false],
        ['1500', 'Property, Plant and Equipment', AccountType::Asset, false, false],

        // ------------------------------------------------------------ liabilities
        ['2000', 'Liabilities', AccountType::Liability, true, false],
        ['2100', 'Accounts Payable', AccountType::Liability, true, false],
        ['2110', 'Trade Payables', AccountType::Liability, false, true],
        // The liability a goods receipt creates before the supplier's invoice is recorded.
        ['2120', 'Goods Received Not Invoiced', AccountType::Liability, false, true],

        ['2200', 'Accrued Expenses', AccountType::Liability, false, false],
        ['2300', 'Taxes Payable', AccountType::Liability, false, false],
        // Every loan the factory owes; the loan records are its subsidiary ledger, so only loan
        // documents post here.
        ['2400', 'Loans Payable', AccountType::Liability, false, true],

        // ---------------------------------------------------------------- equity
        ['3000', 'Equity', AccountType::Equity, true, false],
        ['3100', 'Owner Capital', AccountType::Equity, false, false],
        ['3200', 'Retained Earnings', AccountType::Equity, false, false],
        // Balances what is brought in already running (a loan taken before the system).
        ['3300', 'Opening Balance Equity', AccountType::Equity, false, false],

        // --------------------------------------------------------------- revenue
        ['4000', 'Revenue', AccountType::Revenue, true, false],
        ['4100', 'Sales Revenue', AccountType::Revenue, true, false],
        ['4110', 'Coconut Sales', AccountType::Revenue, false, false],
        ['4120', 'Dehusked Coconut Sales', AccountType::Revenue, false, false],
        ['4130', 'Husk Chip Sales', AccountType::Revenue, false, false],
        ['4140', 'Copra Sales', AccountType::Revenue, false, false],
        ['4150', 'Raw Shell Sales', AccountType::Revenue, false, false],
        ['4160', 'Raw Husk Sales', AccountType::Revenue, false, false],
        ['4170', 'Cocopeat Sales', AccountType::Revenue, false, false],
        ['4180', 'Packing Material Sales', AccountType::Revenue, false, false],

        // --------------------------------------------------------- cost of sales
        ['5000', 'Cost of Sales', AccountType::CostOfSales, true, false],
        ['5100', 'Cost of Goods Sold', AccountType::CostOfSales, true, false],
        ['5110', 'Coconut COGS', AccountType::CostOfSales, false, false],
        ['5120', 'Dehusked Coconut COGS', AccountType::CostOfSales, false, false],
        ['5130', 'Husk Chip COGS', AccountType::CostOfSales, false, false],
        ['5140', 'Copra COGS', AccountType::CostOfSales, false, false],
        ['5150', 'Raw Shell COGS', AccountType::CostOfSales, false, false],
        ['5160', 'Raw Husk COGS', AccountType::CostOfSales, false, false],
        ['5170', 'Cocopeat COGS', AccountType::CostOfSales, false, false],
        ['5180', 'Packing Materials COGS', AccountType::CostOfSales, false, false],

        // ---------------------------------------------------- operating expenses
        ['6000', 'Operating Expenses', AccountType::Expense, true, false],
        ['6100', 'Transport Expense', AccountType::Expense, false, false],
        ['6200', 'Loading and Handling Expense', AccountType::Expense, false, false],
        ['6300', 'Wages and Salaries', AccountType::Expense, false, false],
        // A contra-expense: credited when production labour is absorbed into inventory cost
        // (dehusking labour becomes raw-husk cost). The system's to post, so a control
        // account. Its credit balance offsets wages paid until that stock is sold.
        ['6310', 'Production Labour Absorbed', AccountType::Expense, false, true],
        ['6400', 'Electricity', AccountType::Expense, false, false],
        ['6500', 'Fuel', AccountType::Expense, false, false],
        ['6600', 'Repairs and Maintenance', AccountType::Expense, false, false],
        ['6700', 'Factory Overheads', AccountType::Expense, false, false],
        ['6800', 'Administrative Expenses', AccountType::Expense, false, false],
        // Damaged stock written off. The system's to post (StockDamage), so a control account.
        ['6900', 'Stock Damage and Losses', AccountType::Expense, false, true],
        ['6950', 'Loan Interest', AccountType::Expense, false, false],
    ];

    /**
     * When true, only what does not exist yet is created; nothing existing is changed.
     */
    private bool $onlyMissing = false;

    /**
     * Add what a newer version introduced to an installed database, leaving every existing row
     * exactly as people left it. Run by `masters:add-new` on each update.
     */
    public function addMissing(): void
    {
        $this->onlyMissing = true;
        $this->run();
    }

    public function run(): void
    {
        /** @var array<int, int> $headersByDepth code length => account id */
        $headers = [];

        foreach (self::ACCOUNTS as [$code, $name, $type, $isHeader, $isControl]) {
            $parentId = $this->parentFor($code, $headers);
            $existing = $this->onlyMissing ? Account::query()->where('code', $code)->first() : null;

            if ($existing !== null) {
                if ($isHeader) {
                    $headers[$this->levelOf($code)] = $existing->id;
                }

                continue;
            }

            $account = Account::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'account_type' => $type,
                    'parent_id' => $parentId,
                    'normal_balance' => $type->defaultNormalBalance(),
                    'is_control_account' => $isControl,
                    // Headers organise; control accounts are the system's to post to.
                    'allow_manual_posting' => ! $isHeader && ! $isControl,
                    'is_active' => true,
                ],
            );

            if ($isHeader) {
                $headers[$this->levelOf($code)] = $account->id;
            }
        }
    }

    /**
     * The nearest shallower header.
     *
     * The chart is numbered so that depth is visible in the code itself: 1000 is a top
     * level, 1100 sits under it, 1110 under that.
     *
     * @param  array<int, int>  $headers
     */
    private function parentFor(string $code, array $headers): ?int
    {
        for ($level = $this->levelOf($code) - 1; $level >= 0; $level--) {
            if (isset($headers[$level])) {
                return $headers[$level];
            }
        }

        return null;
    }

    /**
     * 1000 → 0, 1100 → 1, 1110 → 2.
     */
    private function levelOf(string $code): int
    {
        $trimmed = rtrim($code, '0');

        return max(0, strlen($trimmed) - 1);
    }
}
