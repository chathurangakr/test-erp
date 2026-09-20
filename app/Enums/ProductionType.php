<?php

namespace App\Enums;

/**
 * Kinds of production the factory runs.
 *
 * DEHUSKING and HUSK_CHIPPING are implemented. COPRA is declared so the architecture, the
 * recipe table and the screens are shaped for it, but `isImplemented()` keeps an unbuilt
 * type from being posted by accident.
 *
 * See docs/BUSINESS_RULES.md §18 (dehusking) and §19 (husk chipping).
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
        return in_array($this, [self::Dehusking, self::HuskChipping], true);
    }

    /**
     * Whether outputs are derived from the input rather than entered by the operator.
     *
     * Dehusking is the ONLY confirmed fixed-ratio conversion in the business
     * (docs/BUSINESS_RULES.md §2). Husk chipping and copra must never assume a ratio:
     * their yields genuinely vary, so the operator enters the measured output.
     */
    public function hasFixedOutputRatio(): bool
    {
        return $this === self::Dehusking;
    }

    /**
     * Whether the operator measures and enters the output quantities.
     *
     * The inverse of a fixed ratio, stated positively because it is what the screens and
     * the posting action actually branch on.
     */
    public function hasOperatorEnteredOutputs(): bool
    {
        return ! $this->hasFixedOutputRatio();
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
