<?php

namespace App\Services\Accounting;

use App\Enums\CustomerReceiptStatus;
use App\Enums\SalesCreditNoteSettlement;
use App\Enums\SalesCreditNoteStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesReturnSettlement;
use App\Enums\SalesReturnStatus;
use App\Models\Customer;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What customers owe the factory.
 *
 * **Nothing here is stored.** An invoice's outstanding balance is its total less the receipts
 * allocated to it and the returns credited against it (docs/ACCOUNTING_RULES.md §32), computed
 * on request — the same rule that keeps accounts without balances,
 * products without stock and suppliers without payables. A customer has no receivable column
 * and must never grow one.
 *
 * Only documents standing in the books count: a draft invoice is owed nothing, a reversed one
 * has been cancelled by an opposite journal, and a reversed receipt's allocations stop
 * counting without being deleted.
 *
 * See docs/ACCOUNTING_RULES.md §21.
 */
class AccountsReceivableService
{
    public function invoiceTotal(SalesInvoice $invoice): string
    {
        return DecimalMath::cost((string) $invoice->lines()->sum('amount'));
    }

    /**
     * How much of this invoice has been received: by receipts still posted, and by standing
     * customer advances applied to it.
     */
    public function receivedAmount(SalesInvoice $invoice, ?CarbonInterface $asOf = null): string
    {
        $receipts = (string) $this->allocationQuery($asOf)
            ->where('sales_invoice_id', $invoice->getKey())
            ->sum('amount');

        // Money received before the invoice, set against it later, is money received too.
        return DecimalMath::cost(DecimalMath::add(DecimalMath::of($receipts), app(AdvanceBalances::class)->appliedToSalesInvoice($invoice, $asOf)));
    }

    /**
     * How much of this invoice has been credited without money: standing sales returns (goods
     * brought back, or an exchange paid for by them) and standing credit notes (discounts and
     * quality allowances).
     */
    public function creditedAmount(SalesInvoice $invoice, ?CarbonInterface $asOf = null): string
    {
        $returns = (string) SalesReturn::query()
            ->where('credit_invoice_id', $invoice->getKey())
            ->where('status', SalesReturnStatus::Posted->value)
            ->when($asOf, fn ($query, CarbonInterface $date) => $query->whereOnOrBefore('return_date', $date->toDateString()))
            ->sum('credit_amount');
        $creditNotes = (string) SalesCreditNote::query()
            ->where('sales_invoice_id', $invoice->getKey())
            ->where('settlement', SalesCreditNoteSettlement::Credit->value)
            ->where('status', SalesCreditNoteStatus::Posted->value)
            ->when($asOf, fn ($query, CarbonInterface $date) => $query->whereOnOrBefore('credit_date', $date->toDateString()))
            ->sum('amount');

        return DecimalMath::cost(DecimalMath::add(DecimalMath::of($returns), DecimalMath::of($creditNotes)));
    }

    /**
     * How much money may still be given back on this invoice: what the customer paid on it, less
     * what returns (REFUND) and credit notes (REFUND) have already given back. Money back never
     * changes what the invoice is owed — it is paid out of cash or bank.
     */
    public function refundableAmount(SalesInvoice $invoice): string
    {
        $returns = (string) SalesReturn::query()
            ->where('original_invoice_id', $invoice->getKey())
            ->where('settlement', SalesReturnSettlement::Refund->value)
            ->where('status', SalesReturnStatus::Posted->value)
            ->sum('credit_amount');
        $creditNotes = (string) SalesCreditNote::query()
            ->where('sales_invoice_id', $invoice->getKey())
            ->where('settlement', SalesCreditNoteSettlement::Refund->value)
            ->where('status', SalesCreditNoteStatus::Posted->value)
            ->sum('amount');

        return DecimalMath::cost(DecimalMath::sub($this->receivedAmount($invoice), DecimalMath::add(DecimalMath::of($returns), DecimalMath::of($creditNotes))));
    }

    /**
     * Received and credited together: everything that has settled the invoice.
     */
    public function settledAmount(SalesInvoice $invoice, ?CarbonInterface $asOf = null): string
    {
        return DecimalMath::cost(DecimalMath::add($this->receivedAmount($invoice, $asOf), $this->creditedAmount($invoice, $asOf)));
    }

    /**
     * What the invoice is still owed.
     *
     * A reversed or cancelled invoice is owed nothing regardless of what was allocated to it
     * — its journal has been undone.
     */
    public function outstanding(SalesInvoice $invoice, ?CarbonInterface $asOf = null): string
    {
        if (! $invoice->status->affectsReceivables()) {
            return DecimalMath::cost('0');
        }

        return DecimalMath::cost(DecimalMath::sub(
            $this->invoiceTotal($invoice),
            $this->settledAmount($invoice, $asOf),
        ));
    }

    public function customerOutstanding(Customer $customer, ?CarbonInterface $asOf = null): string
    {
        $total = DecimalMath::zero();

        foreach ($this->outstandingInvoices($customer, $asOf) as $invoice) {
            $total = DecimalMath::add($total, $this->outstanding($invoice, $asOf));
        }

        return DecimalMath::cost($total);
    }

    /**
     * Every receivable across every customer — the figure that must equal the Trade
     * Receivables general-ledger balance.
     */
    public function totalOutstanding(?CarbonInterface $asOf = null): string
    {
        $total = DecimalMath::zero();

        foreach ($this->receivableInvoices($asOf)->get() as $invoice) {
            $total = DecimalMath::add($total, $this->outstanding($invoice, $asOf));
        }

        return DecimalMath::cost($total);
    }

    /**
     * A customer's invoices that are still owed something, oldest first.
     *
     * @return Collection<int, SalesInvoice>
     */
    public function outstandingInvoices(Customer $customer, ?CarbonInterface $asOf = null): Collection
    {
        return $this->receivableInvoices($asOf)
            ->where('customer_id', $customer->getKey())
            ->with('lines')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (SalesInvoice $invoice): bool => DecimalMath::isPositive($this->outstanding($invoice, $asOf)))
            ->values();
    }

    /**
     * The aging report, bucketed by how overdue each invoice is.
     *
     * Reversed and cancelled invoices appear nowhere, and a reversed receipt stops reducing
     * what is owed.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>, as_of: string}
     */
    public function aging(?CarbonInterface $asOf = null): array
    {
        $asOf ??= BusinessClock::today();
        $buckets = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

        $byCustomer = [];
        $totals = array_fill_keys([...$buckets, 'total'], DecimalMath::zero());

        foreach ($this->receivableInvoices($asOf)->with(['lines', 'customer:id,code,name'])->get() as $invoice) {
            $outstanding = $this->outstanding($invoice, $asOf);

            if (! DecimalMath::isPositive($outstanding)) {
                continue;
            }

            $bucket = $this->bucketFor($invoice, $asOf);
            $customerId = $invoice->customer_id;

            if (! isset($byCustomer[$customerId])) {
                $byCustomer[$customerId] = array_merge(
                    [
                        'customer_id' => $customerId,
                        'customer_code' => $invoice->customer?->code,
                        'customer_name' => $invoice->customer?->name,
                    ],
                    array_fill_keys([...$buckets, 'total'], DecimalMath::zero()),
                );
            }

            $byCustomer[$customerId][$bucket] = DecimalMath::add($byCustomer[$customerId][$bucket], $outstanding);
            $byCustomer[$customerId]['total'] = DecimalMath::add($byCustomer[$customerId]['total'], $outstanding);

            $totals[$bucket] = DecimalMath::add($totals[$bucket], $outstanding);
            $totals['total'] = DecimalMath::add($totals['total'], $outstanding);
        }

        $rows = array_map(function (array $row) use ($buckets): array {
            foreach ([...$buckets, 'total'] as $key) {
                $row[$key] = DecimalMath::cost($row[$key]);
            }

            return $row;
        }, array_values($byCustomer));

        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['customer_code'], (string) $b['customer_code']));

        return [
            'rows' => $rows,
            'totals' => array_map(fn (string $value): string => DecimalMath::cost($value), $totals),
            'as_of' => $asOf->toDateString(),
        ];
    }

    private function bucketFor(SalesInvoice $invoice, CarbonInterface $asOf): string
    {
        $due = $invoice->due_date;

        if ($due === null || $due->startOfDay()->greaterThanOrEqualTo($asOf->copy()->startOfDay())) {
            return 'current';
        }

        $daysOverdue = (int) $due->startOfDay()->diffInDays($asOf->copy()->startOfDay());

        return match (true) {
            $daysOverdue <= 30 => 'days_1_30',
            $daysOverdue <= 60 => 'days_31_60',
            $daysOverdue <= 90 => 'days_61_90',
            default => 'days_90_plus',
        };
    }

    /**
     * Invoices that stand in the books, optionally as of a date.
     *
     * @return Builder<SalesInvoice>
     */
    private function receivableInvoices(?CarbonInterface $asOf = null)
    {
        return SalesInvoice::query()
            ->whereIn('status', [
                SalesInvoiceStatus::Posted->value,
                SalesInvoiceStatus::PartiallyPaid->value,
                SalesInvoiceStatus::Paid->value,
            ])
            ->when($asOf, fn ($query, CarbonInterface $date) => $query->whereOnOrBefore('invoice_date', $date->toDateString()));
    }

    /**
     * Allocations of receipts that are still posted.
     *
     * @return Builder<CustomerReceiptAllocation>
     */
    private function allocationQuery(?CarbonInterface $asOf = null)
    {
        return CustomerReceiptAllocation::query()
            ->whereHas('receipt', function ($query) use ($asOf): void {
                $query->where('status', CustomerReceiptStatus::Posted->value);

                if ($asOf !== null) {
                    $query->whereOnOrBefore('receipt_date', $asOf->toDateString());
                }
            });
    }
}
