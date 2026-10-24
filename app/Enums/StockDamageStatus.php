<?php

namespace App\Enums;

/**
 * A stock damage write-off is recorded in one step, so it has no draft: POSTED once the stock
 * has left, REVERSED when a mistake was undone by opposite ledger and journal entries. It is
 * never deleted.
 */
enum StockDamageStatus: string
{
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Posted => 'Written off',
            self::Reversed => 'Reversed',
        });
    }

    public function canReverse(): bool
    {
        return $this === self::Posted;
    }
}
