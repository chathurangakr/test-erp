<?php

namespace App\Enums;

/**
 * Which recorded quantity a sales line is priced against.
 *
 * Raw Coconut Shell is stocked in NOS but may be sold by weight, so the quantity that
 * revenue is calculated from is not always the quantity that leaves stock.
 *
 * This is driven entirely by whether the PRODUCT has a secondary unit of measure — never by
 * a hard-coded product code. Any future dual-UOM product gets the behaviour automatically,
 * and every single-UOM product is restricted to PRIMARY.
 *
 * Note that cost is unaffected: stock always moves in the primary unit, so cost of goods
 * sold is always primary quantity x inventory unit cost, whatever the pricing basis.
 * See docs/BUSINESS_RULES.md §17.
 */
enum PricingBasis: string
{
    /** Priced on the stocked quantity, e.g. Rs/NOS. */
    case Primary = 'PRIMARY';

    /** Priced on the secondary quantity, e.g. Rs/KG for shell. */
    case Secondary = 'SECONDARY';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary unit',
            self::Secondary => 'Secondary unit',
        };
    }

    public function isSecondary(): bool
    {
        return $this === self::Secondary;
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
