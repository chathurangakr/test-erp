<?php

namespace App\Enums;

/**
 * Kinds of production the factory runs.
 *
 * All three are implemented. `isImplemented()` remains as the guard that keeps a future,
 * unbuilt type from being posted by accident.
 *
 * See docs/BUSINESS_RULES.md §18 (dehusking), §19 (husk chipping) and §20 (copra).
 */
enum ProductionType: string
{
    case Dehusking = 'DEHUSKING';
    case HuskChipping = 'HUSK_CHIPPING';
    case Copra = 'COPRA';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Dehusking => 'Dehusking',
            self::HuskChipping => 'Husk chipping',
            self::Copra => 'Copra production',
        });
    }

    /**
     * Whether a posting workflow exists for this type yet.
     *
     * Every declared type is currently postable. The check is kept because the enum is how
     * a new production type is introduced, and a type must not become postable simply by
     * being named.
     */
    public function isImplemented(): bool
    {
        return in_array($this, [self::Dehusking, self::HuskChipping, self::Copra], true);
    }

    /**
     * Whether EVERY output is derived from the input rather than entered by the operator.
     *
     * Dehusking is the only type where that is true of all outputs: one coconut always
     * yields one dehusked coconut and one husk (docs/BUSINESS_RULES.md §2).
     *
     * This is a coarse, type-level answer. The authority on any individual output is
     * `ProductionRecipeOutput::derivesQuantity()`, because copra mixes the two — its shell
     * count is derived 1:1 while its copra weight is measured.
     */
    public function hasFixedOutputRatio(): bool
    {
        return $this === self::Dehusking;
    }

    /**
     * Whether the operator measures and enters AT LEAST ONE output quantity.
     *
     * The inverse of the above, stated positively because it is what the screens branch on
     * when deciding whether to show an input box or a derived, read-only figure.
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
