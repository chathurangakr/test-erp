<?php

namespace App\Enums;

/**
 * What happened to returnable bags lent to a customer. See docs/INVENTORY_RULES.md §18.
 *
 *   GIVEN     the bags went out with the customer's goods — store → with customers
 *   RETURNED  the customer brought bags back — with customers → store
 *   LOST      bags the customer will not bring back, written off as a loss
 */
enum CustomerBagMovementKind: string
{
    case Given = 'GIVEN';
    case Returned = 'RETURNED';
    case Lost = 'LOST';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Given => 'Given to customer',
            self::Returned => 'Returned by customer',
            self::Lost => 'Lost',
        });
    }

    /** Whether the movement takes bags out of what the customer holds. */
    public function reducesHolding(): bool
    {
        return $this !== self::Given;
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
