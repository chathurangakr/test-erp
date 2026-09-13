<?php

namespace App\Enums;

/**
 * Purchase order lifecycle.
 *
 *   DRAFT ──approve──▶ APPROVED ──receive──▶ PARTIALLY_RECEIVED ──receive──▶ RECEIVED
 *     │                   │                        │
 *     └──cancel──▶ CANCELLED ◀──cancel─────────────┘
 *
 * Only DRAFT may be freely edited. Once approved a purchase order is a controlled
 * document. See docs/BUSINESS_RULES.md §11.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case PartiallyReceived = 'PARTIALLY_RECEIVED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::PartiallyReceived => 'Partially received',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Only a draft may have its lines and header freely changed. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Stock may only be received against an open, approved order. */
    public function canReceive(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyReceived], true);
    }

    public function canApprove(): bool
    {
        return $this === self::Draft;
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Received, self::Cancelled], true);
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
