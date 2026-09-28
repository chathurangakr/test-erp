<?php

namespace App\Enums;

/**
 * Whether a ledger line increases or decreases stock.
 *
 * Quantities are always stored as positive numbers; this enum alone determines the sign.
 */
enum InventoryDirection: string
{
    case In = 'IN';
    case Out = 'OUT';

    public function opposite(): self
    {
        return match ($this) {
            self::In => self::Out,
            self::Out => self::In,
        };
    }

    public function isIn(): bool
    {
        return $this === self::In;
    }

    public function isOut(): bool
    {
        return $this === self::Out;
    }

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::In => 'In',
            self::Out => 'Out',
        });
    }
}
