<?php

namespace App\Services\Accounting;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\GoodsReceiptStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\SupplierInvoiceLineType;
use App\Enums\SupplierInvoiceStatus;
use App\Models\AccountingSetting;
use App\Models\GoodsReceipt;
use App\Models\SupplierInvoiceLine;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * What each goods receipt still owes, and to nobody in particular.
 *
 * Receiving stock creates an accrual — `Dr inventory / Cr Goods Received Not Invoiced` —
 * because the goods are here but the invoices are not. Supplier invoices then clear that
 * accrual as they arrive.
 *
 * **The original accrual is read from the GRN's posted journal**, specifically the credit to
 * the GRNI account. It is never inferred from quantity × unit cost: the journal is what the
 * books actually say, and re-deriving it would eventually disagree with them.
 *
 * **One receipt may be cleared by several suppliers.** A receipt carrying goods, transport
 * and loading accrues one figure but is invoiced by three different companies, so matching is
 * per-amount and partial, never one-invoice-per-receipt.
 *
 * See docs/ACCOUNTING_RULES.md §17.
 */
class GrniReconciliationService
{
    public function __construct(private readonly RecordOperationalJournal $journals) {}

    /**
     * The accrual this receipt raised, or '0' if it never raised one.
     *
     * Zero means the receipt cannot be matched at all: either it predates the accounting
     * cutover, or its journal has been reversed.
     */
    public function originalAccrual(GoodsReceipt $receipt): string
    {
        $journal = $this->journals->originalJournalFor($receipt);

        if ($journal === null || $journal->status !== JournalEntryStatus::Posted) {
            return DecimalMath::cost('0');
        }

        $grniAccountId = AccountingSetting::current()?->grni_account_id;

        if ($grniAccountId === null) {
            return DecimalMath::cost('0');
        }

        // The credit to GRNI on the receipt's own journal — the authoritative figure.
        return DecimalMath::cost($journal->lines
            ->where('account_id', $grniAccountId)
            ->reduce(
                fn (string $carry, $line): string => DecimalMath::add($carry, DecimalMath::of($line->credit)),
                DecimalMath::zero(),
            ));
    }

    /**
     * How much of this receipt's accrual supplier invoices have already cleared.
     *
     * Only invoices that stand in the books count: a draft has not cleared anything, and a
     * reversed one has given back what it cleared.
     */
    public function matchedAmount(GoodsReceipt $receipt): string
    {
        return DecimalMath::cost(
            (string) SupplierInvoiceLine::query()
                ->where('goods_receipt_id', $receipt->getKey())
                ->where('line_type', SupplierInvoiceLineType::GrniMatch)
                ->whereHas('invoice', fn ($query) => $query->whereIn('status', [
                    SupplierInvoiceStatus::Posted->value,
                    SupplierInvoiceStatus::PartiallyPaid->value,
                    SupplierInvoiceStatus::Paid->value,
                ]))
                ->sum('amount')
        );
    }

    /**
     * Accrual not yet invoiced.
     */
    public function remaining(GoodsReceipt $receipt): string
    {
        return DecimalMath::cost(DecimalMath::sub(
            $this->originalAccrual($receipt),
            $this->matchedAmount($receipt),
        ));
    }

    /**
     * Whether a supplier invoice may allocate against this receipt at all.
     *
     * A receipt that never raised an accrual — pre-cutover, or reversed — has nothing to
     * clear, and matching against it would credit Trade Payables with no corresponding debit
     * to give back.
     */
    public function isMatchable(GoodsReceipt $receipt): bool
    {
        if ($receipt->status !== GoodsReceiptStatus::Posted) {
            return false;
        }

        return DecimalMath::isPositive($this->originalAccrual($receipt));
    }

    /**
     * The invoices that have cleared part of this receipt.
     *
     * @return Collection<int, SupplierInvoiceLine>
     */
    public function matchingLines(GoodsReceipt $receipt): Collection
    {
        return SupplierInvoiceLine::query()
            ->where('goods_receipt_id', $receipt->getKey())
            ->where('line_type', SupplierInvoiceLineType::GrniMatch)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [
                SupplierInvoiceStatus::Posted->value,
                SupplierInvoiceStatus::PartiallyPaid->value,
                SupplierInvoiceStatus::Paid->value,
            ]))
            ->with(['invoice.supplier:id,code,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * One row per accrual-raising receipt: original, matched, remaining and a status.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     original_total: string,
     *     matched_total: string,
     *     remaining_total: string
     * }
     */
    public function report(): array
    {
        $rows = [];
        $original = DecimalMath::zero();
        $matched = DecimalMath::zero();

        $receipts = GoodsReceipt::query()
            ->with(['supplier:id,code,name'])
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->get();

        foreach ($receipts as $receipt) {
            $accrual = $this->originalAccrual($receipt);

            if (! DecimalMath::isPositive($accrual)) {
                // Never raised an accrual, or it has been reversed away: nothing to report.
                continue;
            }

            $cleared = $this->matchedAmount($receipt);
            $remaining = DecimalMath::cost(DecimalMath::sub($accrual, $cleared));

            $original = DecimalMath::add($original, $accrual);
            $matched = DecimalMath::add($matched, $cleared);

            $rows[] = [
                'goods_receipt_id' => $receipt->id,
                'grn_number' => $receipt->grn_number,
                'receipt_date' => $receipt->receipt_date?->toDateString(),
                'supplier_code' => $receipt->supplier?->code,
                'supplier_name' => $receipt->supplier?->name,
                'original_accrual' => $accrual,
                'matched_amount' => $cleared,
                'remaining_amount' => $remaining,
                'status' => $this->statusFor($cleared, $remaining),
            ];
        }

        $original = DecimalMath::cost($original);
        $matched = DecimalMath::cost($matched);

        return [
            'rows' => $rows,
            'original_total' => $original,
            'matched_total' => $matched,
            'remaining_total' => DecimalMath::cost(DecimalMath::sub($original, $matched)),
        ];
    }

    /**
     * The total accrual still uninvoiced across every receipt.
     *
     * This must equal the GRNI account's general-ledger balance — the critical invariant of
     * this module.
     */
    public function totalRemaining(): string
    {
        return $this->report()['remaining_total'];
    }

    private function statusFor(string $matched, string $remaining): string
    {
        if (DecimalMath::isZero($matched)) {
            return 'UNMATCHED';
        }

        return DecimalMath::isZero($remaining) ? 'FULLY_MATCHED' : 'PARTIALLY_MATCHED';
    }
}
