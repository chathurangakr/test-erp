<?php

namespace App\Enums;

/**
 * Loans and loan payments are recorded in one step: POSTED once written to the books, REVERSED
 * when a mistake was undone by an opposite journal. Neither is ever deleted. Whether a loan is
 * paid off is derived from what is still owed, never stored.
 */
enum LoanDocumentStatus: string
{
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Posted => 'Recorded',
            self::Reversed => 'Reversed',
        });
    }

    public function canReverse(): bool
    {
        return $this === self::Posted;
    }
}
