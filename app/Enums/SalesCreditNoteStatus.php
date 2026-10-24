<?php

namespace App\Enums;

/**
 * A credit note is recorded in one step, so it has no draft: POSTED once its journal stands,
 * REVERSED when a mistake was undone by the opposite journal. It is never deleted.
 */
enum SalesCreditNoteStatus: string
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
