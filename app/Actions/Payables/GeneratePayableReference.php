<?php

namespace App\Actions\Payables;

use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Support\DailyDocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Internal AP document numbers: PINV-YYYYMMDD-000001 and PAY-YYYYMMDD-000001.
 *
 * The supplier's own invoice number is stored separately and never used as an identifier
 * here: two suppliers may reasonably use the same number, and a supplier may reuse one after
 * a system of their own changes. The internal number is ours and is unique.
 */
class GeneratePayableReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forInvoice(?CarbonInterface $date = null): string
    {
        return $this->sequence->next(
            'PINV',
            SupplierInvoice::class,
            'internal_invoice_number',
            $date === null ? null : Carbon::instance($date),
        );
    }

    public function forPayment(?CarbonInterface $date = null): string
    {
        return $this->sequence->next(
            'PAY',
            SupplierPayment::class,
            'payment_number',
            $date === null ? null : Carbon::instance($date),
        );
    }
}
