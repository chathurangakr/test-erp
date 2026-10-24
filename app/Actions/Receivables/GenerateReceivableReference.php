<?php

namespace App\Actions\Receivables;

use App\Models\CustomerReceipt;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Support\DailyDocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * AR document numbers: SINV-YYYYMMDD-000001 and REC-YYYYMMDD-000001.
 *
 * Reuses the shared daily sequence, so the concurrency handling lives in one place.
 */
class GenerateReceivableReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forInvoice(?CarbonInterface $date = null): string
    {
        return $this->sequence->next(
            'SINV',
            SalesInvoice::class,
            'invoice_number',
            $date === null ? null : Carbon::instance($date),
        );
    }

    public function forCreditNote(?CarbonInterface $date = null): string
    {
        return $this->sequence->next(
            'CRN',
            SalesCreditNote::class,
            'credit_note_number',
            $date === null ? null : Carbon::instance($date),
        );
    }

    public function forReceipt(?CarbonInterface $date = null): string
    {
        return $this->sequence->next(
            'REC',
            CustomerReceipt::class,
            'receipt_number',
            $date === null ? null : Carbon::instance($date),
        );
    }
}
