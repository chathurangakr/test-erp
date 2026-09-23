<?php

namespace App\Actions\Payables;

use App\Enums\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Services\Accounting\AccountsPayableService;
use App\Support\DecimalMath;

/**
 * Brings an invoice's status back in line with what it still owes.
 *
 * POSTED, PARTIALLY_PAID and PAID are three names for one underlying fact — how much of the
 * invoice remains outstanding — so the status is derived here rather than typed anywhere. A
 * reversed or cancelled invoice is left alone: those states say something about the document
 * itself, not about its balance.
 */
class RefreshSupplierInvoiceStatus
{
    public function __construct(private readonly AccountsPayableService $payables) {}

    public function handle(SupplierInvoice $invoice): SupplierInvoice
    {
        if (! $invoice->status->affectsPayables()) {
            return $invoice;
        }

        $total = $this->payables->invoiceTotal($invoice);
        $paid = $this->payables->paidAmount($invoice);

        $status = match (true) {
            DecimalMath::compare($paid, $total) >= 0 => SupplierInvoiceStatus::Paid,
            DecimalMath::isPositive($paid) => SupplierInvoiceStatus::PartiallyPaid,
            default => SupplierInvoiceStatus::Posted,
        };

        if ($invoice->status !== $status) {
            $invoice->forceFill(['status' => $status])->save();
        }

        return $invoice->refresh();
    }
}
