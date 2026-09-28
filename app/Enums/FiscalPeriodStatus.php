<?php

namespace App\Enums;

/**
 * Fiscal period lifecycle.
 *
 *   OPEN ──close──▶ CLOSED
 *
 * Reopening is deliberately not implemented: it would let posted history change shape after
 * a period was reported on. If the business ever needs it, it becomes an explicit,
 * audited action rather than a status flip.
 */
enum FiscalPeriodStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        });
    }

    public function acceptsPostings(): bool
    {
        return $this === self::Open;
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
