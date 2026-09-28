<?php

namespace App\Enums;

/**
 * The six classes of account in the chart.
 *
 * COST_OF_SALES is a type of its own rather than a flavour of EXPENSE. Gross profit —
 * revenue less cost of sales, before operating expenses — is already a first-class figure
 * in this system, and making the distinction a subtype would force every future report to
 * know a magic value in order to draw the line in the right place. Both post on the DEBIT
 * side, so the separate case costs nothing.
 */
enum AccountType: string
{
    case Asset = 'ASSET';
    case Liability = 'LIABILITY';
    case Equity = 'EQUITY';
    case Revenue = 'REVENUE';
    case CostOfSales = 'COST_OF_SALES';
    case Expense = 'EXPENSE';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::CostOfSales => 'Cost of Sales',
            self::Expense => 'Expense',
        });
    }

    /**
     * The side this class of account normally carries a balance on.
     *
     * This is the conventional default, not a constraint: `accounts.normal_balance` is
     * stored explicitly, because the accounting model should say what it means rather than
     * infer it. The default exists so a new account is right without being told.
     */
    public function defaultNormalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::CostOfSales, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }

    /**
     * Whether this class appears on the balance sheet (as opposed to the income statement).
     */
    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
