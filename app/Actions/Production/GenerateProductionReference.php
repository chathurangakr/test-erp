<?php

namespace App\Actions\Production;

use App\Models\ProductionOrder;
use App\Support\DailyDocumentSequence;
use Illuminate\Support\Carbon;

/**
 * Generates production document numbers: PRD-YYYYMMDD-000001.
 *
 * Uses the same concurrency-safe daily sequence as inventory, purchasing and sales, and
 * likewise encodes no product or production-type meaning.
 */
class GenerateProductionReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forProductionOrder(?Carbon $date = null): string
    {
        return $this->sequence->next('PRD', ProductionOrder::class, 'production_number', $date);
    }
}
