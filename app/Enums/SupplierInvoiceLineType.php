<?php

namespace App\Enums;

/**
 * What a supplier invoice line is charging for.
 *
 *   GRNI_MATCH      Goods already received. The line clears part of a goods receipt's
 *                   Goods Received Not Invoiced accrual: Dr GRNI.
 *   DIRECT_EXPENSE  An invoice with no goods behind it — electricity, repairs — charged
 *                   straight to an expense account: Dr that account.
 *
 * Both credit Trade Payables, which comes from configuration and never appears on a line.
 */
enum SupplierInvoiceLineType: string
{
    case GrniMatch = 'GRNI_MATCH';
    case DirectExpense = 'DIRECT_EXPENSE';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::GrniMatch => 'Goods received (GRNI match)',
            self::DirectExpense => 'Direct expense',
        });
    }

    public function isGrniMatch(): bool
    {
        return $this === self::GrniMatch;
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
