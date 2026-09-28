<?php

namespace App\Enums;

/**
 * Supplier payment lifecycle — the same shape as every other document here.
 *
 *   DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
 *     │
 *     └──cancel──▶ CANCELLED
 *
 * Only a POSTED payment reduces what an invoice still owes. A REVERSED payment's allocations
 * remain on record but stop counting, so the invoice's outstanding balance returns to what it
 * was without anything being deleted.
 */
enum SupplierPaymentStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';
    case Cancelled = 'CANCELLED';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
            self::Cancelled => 'Cancelled',
        });
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

    /** Whether this payment's allocations reduce an invoice's outstanding balance. */
    public function reducesPayables(): bool
    {
        return $this === self::Posted;
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
