<?php

namespace App\Enums;

/**
 * How a fixed asset was paid for. See docs/FIXED_ASSETS.md.
 *
 *   CASH_BANK  paid in full from a cash or bank account    Dr fixed assets / Cr cash or bank
 *   LOAN       a down payment (maybe none), the rest a      Dr fixed assets / Cr cash or bank (down)
 *              loan opened with it                                           / Cr loans payable (rest)
 *   EXISTING   owned before the system, at what it cost     Dr fixed assets / Cr opening balance equity
 */
enum AssetFunding: string
{
    case CashBank = 'CASH_BANK';
    case Loan = 'LOAN';
    case Existing = 'EXISTING';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::CashBank => 'Paid in full',
            self::Loan => 'Bought on a loan',
            self::Existing => 'Already owned',
        });
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
