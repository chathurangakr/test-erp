<?php

namespace App\Enums;

/**
 * Sales order lifecycle.
 *
 *   DRAFT ──approve──▶ APPROVED ──deliver──▶ PARTIALLY_DELIVERED ──deliver──▶ DELIVERED
 *     │                    │                          │
 *     └────cancel────▶ CANCELLED ◀───────cancel───────┘
 *
 * Mirrors PurchaseOrderStatus: only a DRAFT is freely editable, and an approved order is a
 * controlled document whose commercial values are preserved.
 * See docs/BUSINESS_RULES.md §15.
 */
enum SalesOrderStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case PartiallyDelivered = 'PARTIALLY_DELIVERED';
    case Delivered = 'DELIVERED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::PartiallyDelivered => 'Partially delivered',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canApprove(): bool
    {
        return $this === self::Draft;
    }

    /** Stock may only be delivered against an open, approved order. */
    public function canDeliver(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyDelivered], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
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
