<?php

namespace App\Enums;

/**
 * How a loan came onto the books. See docs/LOANS.md.
 *
 *   RECEIVED  the money was paid into a cash or bank account     Dr cash/bank / Cr loans
 *   EXISTING  a loan already running, entered with what is        Dr opening balance equity / Cr loans
 *             still owed on the day it is entered
 */
enum LoanFunding: string
{
    case Received = 'RECEIVED';
    case Existing = 'EXISTING';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Received => 'New loan — money received',
            self::Existing => 'Loan already running',
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
