<?php

namespace App\Enums;

/**
 * Delivery (dispatch note) lifecycle.
 *
 *   DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
 *     │
 *     └──cancel──▶ CANCELLED
 *
 * As with goods receipts, REVERSED and CANCELLED are distinct: CANCELLED means the delivery
 * never reached inventory, REVERSED means stock left the warehouse and was put back by an
 * opposite ledger transaction.
 * See docs/BUSINESS_RULES.md §16.
 */
enum DeliveryStatus: string
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

    /** Only an unposted draft may be cancelled; a posted delivery must be reversed. */
    public function canCancel(): bool
    {
        return $this === self::Draft;
    }

    /** Whether this delivery ever reached the inventory ledger. */
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
