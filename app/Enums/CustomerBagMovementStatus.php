<?php

namespace App\Enums;

/**
 * A bag movement is recorded in one step, so it has no draft: POSTED once the bags have moved,
 * REVERSED when a mistake was undone. It is never deleted.
 */
enum CustomerBagMovementStatus: string
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
