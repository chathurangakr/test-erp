<?php

namespace App\Enums;

/**
 * WHEN a production recipe's input leaves stock.
 *
 *  - AT_PRODUCTION: posting the run consumes the input and shares its value across the
 *    outputs (dehusking, copra).
 *  - AT_DELIVERY:   posting the run consumes nothing. The outputs enter stock with their raw
 *    material cost PENDING, and the input is backflushed — issued at its weighted average and
 *    added to cost of goods sold — when the outputs are delivered (husk chipping).
 *
 * See docs/BUSINESS_RULES.md §19.
 */
enum InputConsumption: string
{
    case AtProduction = 'AT_PRODUCTION';
    case AtDelivery = 'AT_DELIVERY';

    public function label(): string
    {
        return __(match ($this) {
            self::AtProduction => 'Consumed when produced',
            self::AtDelivery => 'Backflushed at delivery',
        });
    }
}
