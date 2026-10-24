<?php

namespace App\Actions\Sales;

use App\Models\CustomerBagMovement;
use App\Models\Delivery;
use App\Models\SalesOrder;
use App\Support\DailyDocumentSequence;
use Illuminate\Support\Carbon;

/**
 * Generates sales document numbers.
 *
 *   Sales orders: SO-YYYYMMDD-000001
 *   Deliveries:   DN-YYYYMMDD-000001
 *   Bag moves:    BAG-YYYYMMDD-000001
 *
 * Uses the same concurrency-safe daily sequence as inventory and purchasing, and likewise
 * encodes no customer or product meaning.
 */
class GenerateSalesReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forSalesOrder(?Carbon $date = null): string
    {
        return $this->sequence->next('SO', SalesOrder::class, 'so_number', $date);
    }

    public function forDelivery(?Carbon $date = null): string
    {
        return $this->sequence->next('DN', Delivery::class, 'delivery_number', $date);
    }

    public function forBagMovement(?Carbon $date = null): string
    {
        return $this->sequence->next('BAG', CustomerBagMovement::class, 'movement_number', $date);
    }
}
