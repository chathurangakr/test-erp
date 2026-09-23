<?php

namespace App\Enums;

/**
 * Sales invoice lifecycle.
 *
 *   DRAFT ──post──▶ POSTED ──receive──▶ PARTIALLY_PAID ──receive──▶ PAID
 *     │                 │
 *     │                 └──reverse──▶ REVERSED
 *     └──cancel──▶ CANCELLED
 *
 * PARTIALLY_PAID and PAID are **derived** from the receipts allocated to the invoice, never
 * typed: they describe how much is still owed, and the answer to that is the allocations.
 *
 * REVERSED is an **audit correction**, not a credit note. A commercial return or allowance is
 * a different document with different accounting, and is not built — see
 * docs/ACCOUNTING_RULES.md §19.
 */
enum SalesInvoiceStatus: string
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

    /** Whether receipts may still be allocated to this invoice. */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Posted, self::PartiallyPaid], true);
    }

    /**
     * Whether the invoice stands in the books.
     *
     * A REVERSED invoice does not: its journal has been cancelled by an opposite one, so it
     * is owed nothing and appears in no aging report.
     */
    public function affectsReceivables(): bool
    {
        return in_array($this, [self::Posted, self::PartiallyPaid, self::Paid], true);
    }

    public function canReverse(): bool
    {
        return $this->affectsReceivables();
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
