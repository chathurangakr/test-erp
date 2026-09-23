<?php

namespace App\Actions\Receivables;

use App\Enums\SalesInvoiceStatus;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\DecimalMath;

/**
 * Brings an invoice's status back in line with what it is still owed.
 *
 * POSTED, PARTIALLY_PAID and PAID are three names for one underlying fact — how much remains
 * outstanding — so the status is derived here rather than typed anywhere. A reversed or
 * cancelled invoice is left alone: those states describe the document, not its balance.
 */
class RefreshSalesInvoiceStatus
{
    public function __construct(private readonly AccountsReceivableService $receivables) {}

    public function handle(SalesInvoice $invoice): SalesInvoice
    {
        if (! $invoice->status->affectsReceivables()) {
            return $invoice;
        }

        $total = $this->receivables->invoiceTotal($invoice);
        $received = $this->receivables->receivedAmount($invoice);

        $status = match (true) {
            DecimalMath::compare($received, $total) >= 0 => SalesInvoiceStatus::Paid,
            DecimalMath::isPositive($received) => SalesInvoiceStatus::PartiallyPaid,
            default => SalesInvoiceStatus::Posted,
        };

        if ($invoice->status !== $status) {
            $invoice->forceFill(['status' => $status])->save();
        }

        return $invoice->refresh();
    }
}
