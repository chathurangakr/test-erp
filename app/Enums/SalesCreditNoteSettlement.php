<?php

namespace App\Enums;

/**
 * How a credit note reaches the customer: CREDIT reduces what the invoice is still owed; REFUND
 * pays money back from cash or bank for an invoice already paid. See docs/ACCOUNTING_RULES.md §33.
 */
enum SalesCreditNoteSettlement: string
{
    case Credit = 'CREDIT';
    case Refund = 'REFUND';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Credit => 'Reduce what is owed',
            self::Refund => 'Money back',
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
