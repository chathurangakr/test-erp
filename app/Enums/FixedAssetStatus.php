<?php

namespace App\Enums;

/**
 * IN_USE once recorded, SOLD once sold or scrapped (until that sale is reversed), REVERSED when
 * the asset was recorded by mistake. Never deleted.
 */
enum FixedAssetStatus: string
{
    case InUse = 'IN_USE';
    case Sold = 'SOLD';
    case Reversed = 'REVERSED';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::InUse => 'In use',
            self::Sold => 'Sold',
            self::Reversed => 'Reversed',
        });
    }
}
