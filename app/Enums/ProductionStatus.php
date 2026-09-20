<?php

namespace App\Enums;

/**
 * Production order lifecycle.
 *
 *   DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
 *     │
 *     └──cancel──▶ CANCELLED
 *
 * As with goods receipts and deliveries, REVERSED and CANCELLED are distinct: CANCELLED
 * means nothing was ever consumed or produced, REVERSED means it was and has been undone.
 */
enum ProductionStatus: string
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

    public function canCancel(): bool
    {
        return $this === self::Draft;
    }

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
