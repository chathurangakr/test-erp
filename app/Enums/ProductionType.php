<?php

namespace App\Enums;

/**
 * Kinds of production the factory runs.
 *
 * Only DEHUSKING is implemented. The other two are declared so the architecture, the
 * recipe table and the screens are shaped for them from the start, but `isImplemented()`
 * keeps an unbuilt type from being posted by accident.
 *
 * See docs/BUSINESS_RULES.md §18.
 */
enum ProductionType: string
{
    case Dehusking = 'DEHUSKING';
    case HuskChipping = 'HUSK_CHIPPING';
    case Copra = 'COPRA';

    public function label(): string
    {
        return match ($this) {
            self::Dehusking => 'Dehusking',
            self::HuskChipping => 'Husk chipping',
            self::Copra => 'Copra production',
        };
    }

    /**
     * Whether a posting workflow exists for this type yet.
     *
     * Husk chipping and copra have operator-entered output weights and unresolved waste
     * and joint-cost rules, so they are deliberately not postable.
     */
    public function isImplemented(): bool
    {
        return $this === self::Dehusking;
    }

    /**
     * Whether outputs are derived from the input rather than entered by the operator.
     *
     * Dehusking is the only confirmed fixed-ratio conversion in the business
     * (docs/BUSINESS_RULES.md §2); the other two must never assume a ratio.
     */
    public function hasFixedOutputRatio(): bool
    {
        return $this === self::Dehusking;
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
