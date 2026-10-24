<?php

namespace App\Enums;

/**
 * Why a customer's invoice was reduced without goods coming back: a price DISCOUNT given after
 * the sale, or an ALLOWANCE for poor quality.
 */
enum SalesCreditNoteKind: string
{
    case Discount = 'DISCOUNT';
    case Allowance = 'ALLOWANCE';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Discount => 'Discount',
            self::Allowance => 'Quality allowance',
        });
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
