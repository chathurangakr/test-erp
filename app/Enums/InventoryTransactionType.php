<?php

namespace App\Enums;

/**
 * The business event that caused a set of inventory movements.
 *
 * The inventory engine supports these types; the business documents behind them
 * (purchases, sales, production orders) are separate, later modules.
 */
enum InventoryTransactionType: string
{
    case OpeningBalance = 'OPENING_BALANCE';
    case PurchaseReceipt = 'PURCHASE_RECEIPT';
    case SalesIssue = 'SALES_ISSUE';
    case ProductionConsumption = 'PRODUCTION_CONSUMPTION';
    case ProductionOutput = 'PRODUCTION_OUTPUT';
    case Transfer = 'TRANSFER';
    case AdjustmentIn = 'ADJUSTMENT_IN';
    case AdjustmentOut = 'ADJUSTMENT_OUT';
    case Reversal = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::PurchaseReceipt => 'Purchase receipt',
            self::SalesIssue => 'Sales issue',
            self::ProductionConsumption => 'Production consumption',
            self::ProductionOutput => 'Production output',
            self::Transfer => 'Transfer',
            self::AdjustmentIn => 'Adjustment in',
            self::AdjustmentOut => 'Adjustment out',
            self::Reversal => 'Reversal',
        };
    }

    /**
     * A reversal may not itself be reversed — see docs/INVENTORY_RULES.md §12.
     */
    public function isReversible(): bool
    {
        return $this !== self::Reversal;
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
