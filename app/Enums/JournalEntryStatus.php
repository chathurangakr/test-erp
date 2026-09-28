<?php

namespace App\Enums;

/**
 * Journal entry lifecycle — the same shape as every other document in this system.
 *
 *   DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
 *     │
 *     └──cancel──▶ CANCELLED
 *
 * Only a POSTED entry affects account balances. CANCELLED means the entry never reached the
 * books; REVERSED means it did and has been undone by an opposite entry. Neither deletes
 * anything.
 */
enum JournalEntryStatus: string
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
     * Whether entries in this state contribute to account balances.
     *
     * A REVERSED entry still does: its own lines remain posted, and the reversing entry
     * cancels them out. Removing it would rewrite history.
     */
    public function affectsBalances(): bool
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
