<?php

namespace App\Enums;

/**
 * Goods receipt lifecycle.
 *
 *   DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
 *     │
 *     └──cancel──▶ CANCELLED
 *
 * A DRAFT creates no inventory whatsoever. Only posting writes to the ledger, and a posted
 * receipt is immutable — it is corrected by reversal, never edited.
 *
 * REVERSED and CANCELLED are deliberately distinct: CANCELLED means the receipt never
 * reached inventory, while REVERSED means it did and was subsequently undone by an
 * opposite ledger transaction. Conflating them would lose that audit distinction.
 * See docs/BUSINESS_RULES.md §12.
 */
enum GoodsReceiptStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canPost(): bool
    {
        return $this === self::Draft;
    }

    public function canReverse(): bool
    {
        return $this === self::Posted;
    }

    /** Only an unposted draft may be cancelled; a posted receipt must be reversed. */
    public function canCancel(): bool
    {
        return $this === self::Draft;
    }

    /** Whether this receipt ever reached the inventory ledger. */
    public function reachedInventory(): bool
    {
        return in_array($this, [self::Posted, self::Reversed], true);
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
