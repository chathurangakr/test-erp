<?php

namespace App\Enums;

/**
 * Salary advances and monthly payrolls are recorded and paid in one step, so they have no
 * draft: POSTED when the money has gone, REVERSED when a mistake was undone by an opposite
 * journal. Neither is ever deleted.
 */
enum PayrollDocumentStatus: string
{
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Posted => 'Paid',
            self::Reversed => 'Reversed',
        });
    }

    public function canReverse(): bool
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
