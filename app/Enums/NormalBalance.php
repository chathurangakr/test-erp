<?php

namespace App\Enums;

/**
 * The side an account normally carries its balance on.
 *
 * It decides how a raw debit-minus-credit total is presented: a debit-normal account with
 * debits exceeding credits has a POSITIVE balance, and so does a credit-normal account with
 * credits exceeding debits. Nothing about posting changes — a journal line is a debit or a
 * credit regardless of which account it touches.
 */
enum NormalBalance: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        });
    }

    public function isDebit(): bool
    {
        return $this === self::Debit;
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
