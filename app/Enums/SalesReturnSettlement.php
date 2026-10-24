<?php

namespace App\Enums;

/**
 * How a customer's credit for returned goods is settled. See docs/ACCOUNTING_RULES.md §32.
 *
 *   CREDIT    reduce what they owe on an invoice              Dr revenue / Cr trade receivables
 *   EXCHANGE  other goods instead — the credit pays the        Dr revenue / Cr trade receivables
 *             invoice for the replacement goods
 *   REFUND    money back from cash or bank                     Dr revenue / Cr cash or bank
 */
enum SalesReturnSettlement: string
{
    case Credit = 'CREDIT';
    case Exchange = 'EXCHANGE';
    case Refund = 'REFUND';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Credit => 'Reduce what they owe',
            self::Exchange => 'Exchange for other goods',
            self::Refund => 'Money back',
        });
    }

    /** Whether the credit reduces an invoice (rather than paying money out). */
    public function reducesAnInvoice(): bool
    {
        return $this !== self::Refund;
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
