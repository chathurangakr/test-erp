<?php

namespace App\Services\Accounting;

use App\Enums\AdvanceStatus;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceApplication;
use App\Models\SalesInvoice;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierInvoice;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;

/**
 * What is left of customer and supplier advances, and how much of them has settled an invoice —
 * derived from the standing documents, never stored. The invariants:
 *
 *   Customer Advances balance (2130)  ==  total unapplied customer advances
 *   Supplier Advances balance (1230)  ==  total unapplied supplier advances
 *
 * See docs/ACCOUNTING_RULES.md §34.
 */
class AdvanceBalances
{
    /** What is left of one advance: its amount less its standing applications and refunds. */
    public function remaining(CustomerAdvance|SupplierAdvance $advance): string
    {
        if ($advance->status !== AdvanceStatus::Posted) {
            return DecimalMath::cost('0');
        }

        $applied = (string) $advance->applications()->where('status', AdvanceStatus::Posted->value)->sum('amount');
        $refunded = (string) $advance->refunds()->where('status', AdvanceStatus::Posted->value)->sum('amount');

        return DecimalMath::cost(DecimalMath::sub((string) $advance->amount, DecimalMath::add(DecimalMath::of($applied), DecimalMath::of($refunded))));
    }

    /** How much of standing customer advances has been set against this invoice. */
    public function appliedToSalesInvoice(SalesInvoice $invoice, ?CarbonInterface $asOf = null): string
    {
        return DecimalMath::cost(DecimalMath::of((string) CustomerAdvanceApplication::query()
            ->where('sales_invoice_id', $invoice->getKey())
            ->where('status', AdvanceStatus::Posted->value)
            ->when($asOf, fn ($query, CarbonInterface $date) => $query->whereDate('application_date', '<=', $date->toDateString()))
            ->sum('amount')));
    }

    /** How much of standing supplier advances has been set against this invoice. */
    public function appliedToSupplierInvoice(SupplierInvoice $invoice, ?CarbonInterface $asOf = null): string
    {
        return DecimalMath::cost(DecimalMath::of((string) SupplierAdvanceApplication::query()
            ->where('supplier_invoice_id', $invoice->getKey())
            ->where('status', AdvanceStatus::Posted->value)
            ->when($asOf, fn ($query, CarbonInterface $date) => $query->whereDate('application_date', '<=', $date->toDateString()))
            ->sum('amount')));
    }

    /** Every customer advance not yet applied — what the factory owes customers. */
    public function customerTotal(): string
    {
        return $this->total(CustomerAdvance::query()->where('status', AdvanceStatus::Posted->value)->get());
    }

    /** Every supplier advance not yet applied — what suppliers owe the factory. */
    public function supplierTotal(): string
    {
        return $this->total(SupplierAdvance::query()->where('status', AdvanceStatus::Posted->value)->get());
    }

    /**
     * @param  iterable<CustomerAdvance|SupplierAdvance>  $advances
     */
    private function total(iterable $advances): string
    {
        $total = DecimalMath::zero();

        foreach ($advances as $advance) {
            $total = DecimalMath::add($total, $this->remaining($advance));
        }

        return DecimalMath::cost($total);
    }
}
