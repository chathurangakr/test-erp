<?php

namespace App\Services\Accounting;

use App\Enums\SupplierInvoiceStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentAllocation;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the factory owes its suppliers.
 *
 * **Nothing here is stored.** An invoice's outstanding balance is its total less the payments
 * allocated to it, computed on request — the same rule that keeps accounts without balances
 * and products without stock. A supplier has no payable column and must never grow one.
 *
 * Only documents that stand in the books count: a draft invoice owes nothing, a reversed one
 * has been cancelled by an opposite journal, and a reversed payment's allocations stop
 * counting without being deleted.
 *
 * See docs/ACCOUNTING_RULES.md §18.
 */
class AccountsPayableService
{
    /**
     * The invoice's own total, from its lines.
     */
    public function invoiceTotal(SupplierInvoice $invoice): string
    {
        return DecimalMath::cost((string) $invoice->lines()->sum('amount'));
    }

    /**
     * How much of this invoice has been paid: by payments still posted, and by standing supplier
     * advances applied to it.
     */
    public function paidAmount(SupplierInvoice $invoice, ?CarbonInterface $asOf = null): string
    {
        $payments = (string) $this->allocationQuery($asOf)
            ->where('supplier_invoice_id', $invoice->getKey())
            ->sum('amount');

        // Money paid before the invoice, set against it later, is money paid too.
        return DecimalMath::cost(DecimalMath::add(DecimalMath::of($payments), app(AdvanceBalances::class)->appliedToSupplierInvoice($invoice, $asOf)));
    }

    /**
     * What the invoice still owes.
     *
     * A reversed or cancelled invoice owes nothing regardless of what was allocated to it —
     * its journal has been undone.
     */
    public function outstanding(SupplierInvoice $invoice, ?CarbonInterface $asOf = null): string
    {
        if (! $invoice->status->affectsPayables()) {
            return DecimalMath::cost('0');
        }

        return DecimalMath::cost(DecimalMath::sub(
            $this->invoiceTotal($invoice),
            $this->paidAmount($invoice, $asOf),
        ));
    }

    /**
     * Everything one supplier still owes.
     */
    public function supplierOutstanding(Supplier $supplier, ?CarbonInterface $asOf = null): string
    {
        $total = DecimalMath::zero();

        foreach ($this->outstandingInvoices($supplier, $asOf) as $invoice) {
            $total = DecimalMath::add($total, $this->outstanding($invoice, $asOf));
        }

        return DecimalMath::cost($total);
    }

    /**
     * Every payable across every supplier — the figure that must equal the Trade Payables
     * general-ledger balance.
     */
    public function totalOutstanding(?CarbonInterface $asOf = null): string
    {
        $total = DecimalMath::zero();

        foreach ($this->payableInvoices($asOf)->get() as $invoice) {
            $total = DecimalMath::add($total, $this->outstanding($invoice, $asOf));
        }

        return DecimalMath::cost($total);
    }

    /**
     * A supplier's invoices that still owe something, oldest first.
     *
     * @return Collection<int, SupplierInvoice>
     */
    public function outstandingInvoices(Supplier $supplier, ?CarbonInterface $asOf = null): Collection
    {
        return $this->payableInvoices($asOf)
            ->where('supplier_id', $supplier->getKey())
            ->with('lines')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (SupplierInvoice $invoice): bool => DecimalMath::isPositive($this->outstanding($invoice, $asOf)))
            ->values();
    }

    /**
     * The aging report.
     *
     * Buckets are measured from the due date: an invoice not yet due is Current, one a week
     * late is in 1-30, and so on. Reversed and cancelled invoices appear nowhere, and a
     * reversed payment stops reducing what is owed.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, string>,
     *     as_of: string
     * }
     */
    public function aging(?CarbonInterface $asOf = null): array
    {
        $asOf ??= BusinessClock::today();
        $buckets = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

        $bySupplier = [];
        $totals = array_fill_keys([...$buckets, 'total'], DecimalMath::zero());

        foreach ($this->payableInvoices($asOf)->with(['lines', 'supplier:id,code,name'])->get() as $invoice) {
            $outstanding = $this->outstanding($invoice, $asOf);

            if (! DecimalMath::isPositive($outstanding)) {
                continue;
            }

            $bucket = $this->bucketFor($invoice, $asOf);
            $supplierId = $invoice->supplier_id;

            if (! isset($bySupplier[$supplierId])) {
                $bySupplier[$supplierId] = array_merge(
                    [
                        'supplier_id' => $supplierId,
                        'supplier_code' => $invoice->supplier?->code,
                        'supplier_name' => $invoice->supplier?->name,
                    ],
                    array_fill_keys([...$buckets, 'total'], DecimalMath::zero()),
                );
            }

            $bySupplier[$supplierId][$bucket] = DecimalMath::add($bySupplier[$supplierId][$bucket], $outstanding);
            $bySupplier[$supplierId]['total'] = DecimalMath::add($bySupplier[$supplierId]['total'], $outstanding);

            $totals[$bucket] = DecimalMath::add($totals[$bucket], $outstanding);
            $totals['total'] = DecimalMath::add($totals['total'], $outstanding);
        }

        $rows = array_map(function (array $row) use ($buckets): array {
            foreach ([...$buckets, 'total'] as $key) {
                $row[$key] = DecimalMath::cost($row[$key]);
            }

            return $row;
        }, array_values($bySupplier));

        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['supplier_code'], (string) $b['supplier_code']));

        return [
            'rows' => $rows,
            'totals' => array_map(fn (string $value): string => DecimalMath::cost($value), $totals),
            'as_of' => $asOf->toDateString(),
        ];
    }

    /**
     * Which aging bucket an invoice falls in, by how overdue it is.
     */
    private function bucketFor(SupplierInvoice $invoice, CarbonInterface $asOf): string
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
     * @return Builder<SupplierInvoice>
     */
    private function payableInvoices(?CarbonInterface $asOf = null)
    {
        return SupplierInvoice::query()
            ->whereIn('status', [
                SupplierInvoiceStatus::Posted->value,
                SupplierInvoiceStatus::PartiallyPaid->value,
                SupplierInvoiceStatus::Paid->value,
            ])
            ->when($asOf, fn ($query, CarbonInterface $date) => $query->whereOnOrBefore('invoice_date', $date->toDateString()));
    }

    /**
     * Allocations of payments that are still posted.
     *
     * @return Builder<SupplierPaymentAllocation>
     */
    private function allocationQuery(?CarbonInterface $asOf = null)
    {
        return SupplierPaymentAllocation::query()
            ->whereHas('payment', function ($query) use ($asOf): void {
                $query->where('status', SupplierPaymentStatus::Posted->value);

                if ($asOf !== null) {
                    $query->whereOnOrBefore('payment_date', $asOf->toDateString());
                }
            });
    }
}
