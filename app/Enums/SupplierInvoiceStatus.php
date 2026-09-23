<?php

namespace App\Enums;

/**
 * Supplier invoice lifecycle.
 *
 *   DRAFT ──post──▶ POSTED ──pay──▶ PARTIALLY_PAID ──pay──▶ PAID
 *     │                 │
 *     │                 └──reverse──▶ REVERSED
 *     └──cancel──▶ CANCELLED
 *
 * PARTIALLY_PAID and PAID are **derived** from the payments allocated to the invoice, never
 * set by hand: they describe how much is still owed, and the answer to that is the
 * allocations, not a field someone typed.
 *
 * A paid or partly paid invoice is still a controlled document — it is corrected by
 * reversing the payments and then the invoice, never by editing.
 */
enum SupplierInvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Reversed = 'REVERSED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
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

    public function canCancel(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the invoice is a live payable that payments may be allocated to.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Posted, self::PartiallyPaid], true);
    }

    /**
     * Whether the invoice sits in the books at all.
     *
     * A REVERSED invoice does not: its journal has been cancelled by an opposite one, so it
     * owes nothing and appears in no aging report.
     */
    public function affectsPayables(): bool
    {
        return in_array($this, [self::Posted, self::PartiallyPaid, self::Paid], true);
    }

    public function canReverse(): bool
    {
        return $this->affectsPayables();
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
