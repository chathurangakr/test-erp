<?php

namespace App\Enums;

/**
 * A year-end close is recorded in one step: POSTED once its closing journal stands, REVERSED when
 * it was undone by the opposite journal. It is never deleted.
 */
enum FinancialYearClosingStatus: string
{
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Posted => 'Closed',
            self::Reversed => 'Reversed',
        });
    }
}
