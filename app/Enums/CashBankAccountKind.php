<?php

namespace App\Enums;

/**
 * Whether configured money is physically held or held by a bank.
 *
 * The distinction is reporting, not accounting: both are ordinary asset accounts and both
 * post identically. It exists so "how much cash is in the building" can be answered
 * separately from "how much is in the bank", which are different operational questions.
 */
enum CashBankAccountKind: string
{
    case Cash = 'CASH';
    case Bank = 'BANK';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank',
        };
    }

    /**
     * Whether the bank name, branch and account number are meaningful for this kind.
     */
    public function hasBankDetails(): bool
    {
        return $this === self::Bank;
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
