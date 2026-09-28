<?php

namespace App\Enums;

/**
 * Fund transfer lifecycle — the same shape as every other document here.
 *
 *   DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
 *     │
 *     └──cancel──▶ CANCELLED
 *
 * A DRAFT has moved no money and written no journal. CANCELLED and REVERSED are different
 * facts: a cancelled transfer never reached the books, a reversed one did and was undone by
 * an opposite journal. Neither is ever deleted.
 */
enum FundTransferStatus: string
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
