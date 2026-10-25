<?php

namespace App\Enums;

/**
 * A customer or supplier advance, and each application of one to an invoice, is recorded in one
 * step: POSTED once its journal stands, REVERSED when a mistake was undone by the opposite journal.
 * Never deleted.
 */
enum AdvanceStatus: string
{
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        });
    }

    public function canReverse(): bool
    {
        return $this === self::Posted;
    }
}
