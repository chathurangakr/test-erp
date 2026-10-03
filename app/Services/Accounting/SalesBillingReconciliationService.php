<?php

namespace App\Services\Accounting;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\DeliveryStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\SalesInvoiceStatus;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\SalesInvoiceLine;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * What has been delivered but not yet billed.
 *
 * A delivery moves stock and records what it cost — `Dr cost of sales / Cr inventory` — and
 * deliberately recognises no revenue. The sales invoice does that, later, and possibly in
 * parts. The gap between the two is what this service reports.
 *
 * **The billable figure is `DeliveryLine.sales_value`**, computed when the goods went out
 * from the line's own pricing basis. It is never recalculated here, which is what keeps the
 * dual-unit case honest: Raw Shell sold at Rs.80 per measured KG carries its stored value
 * through to the invoice with **no second NOS-to-KG conversion anywhere in AR**.
 *
 * Note the asymmetry with payables: a goods receipt's accrual is read from its journal,
 * because the journal is where GRNI lives. A delivery's journal holds only cost, so the line
 * itself is the authoritative source of the revenue figure.
 *
 * See docs/ACCOUNTING_RULES.md §20.
 */
class SalesBillingReconciliationService
{
    public function __construct(
        private readonly RecordOperationalJournal $journals,
        private readonly AccountingPolicy $policy,
    ) {}

    /**
     * The value this delivered line may be billed for, in total.
     */
    public function originalValue(DeliveryLine $line): string
    {
        return DecimalMath::cost(DecimalMath::of($line->sales_value ?? '0'));
    }

    /**
     * How much of this line invoices have already billed.
     *
     * Only invoices standing in the books count: a draft has billed nothing, and a reversed
     * one has given back what it billed.
     */
    public function billedAmount(DeliveryLine $line): string
    {
        return DecimalMath::cost(
            (string) SalesInvoiceLine::query()
                ->where('delivery_line_id', $line->getKey())
                ->whereHas('invoice', fn ($query) => $query->whereIn('status', $this->liveInvoiceStatuses()))
                ->sum('amount')
        );
    }

    /**
     * Value delivered but not yet invoiced.
     */
    public function remaining(DeliveryLine $line): string
    {
        return DecimalMath::cost(DecimalMath::sub(
            $this->originalValue($line),
            $this->billedAmount($line),
        ));
    }

    /**
     * Whether a sales invoice may bill against this delivery at all.
     *
     * The delivery must have reached the general ledger. Recognising revenue for a delivery
     * whose cost of sales was never posted — one from before the accounting cutover, or one
     * that has been reversed — would leave the income statement showing a sale with no cost
     * against it.
     *
     * One delivery reaches the ledger without a journal: one posted under accounting whose
     * goods genuinely cost nothing, so there was no amount to journal. It is billable — its
     * zero cost of sales is the truth, not a gap.
     */
    public function isBillable(Delivery $delivery): bool
    {
        if ($delivery->status !== DeliveryStatus::Posted) {
            return false;
        }

        $journal = $this->journals->originalJournalFor($delivery);

        if ($journal !== null) {
            return $journal->status === JournalEntryStatus::Posted;
        }

        return $this->policy->appliesTo($delivery->delivery_date)
            && $delivery->lines->every(
                fn (DeliveryLine $line): bool => DecimalMath::isZero(DecimalMath::of($line->cost_of_goods_sold ?? '0')),
            );
    }

    /**
     * Everything a delivery may still be billed for.
     */
    public function deliveryRemaining(Delivery $delivery): string
    {
        $total = DecimalMath::zero();

        foreach ($delivery->lines as $line) {
            $total = DecimalMath::add($total, $this->remaining($line));
        }

        return DecimalMath::cost($total);
    }

    /**
     * How much of this delivery has been billed.
     */
    public function deliveryBilled(Delivery $delivery): string
    {
        $total = DecimalMath::zero();

        foreach ($delivery->lines as $line) {
            $total = DecimalMath::add($total, $this->billedAmount($line));
        }

        return DecimalMath::cost($total);
    }

    public function deliveryValue(Delivery $delivery): string
    {
        $total = DecimalMath::zero();

        foreach ($delivery->lines as $line) {
            $total = DecimalMath::add($total, $this->originalValue($line));
        }

        return DecimalMath::cost($total);
    }

    /**
     * The invoices that have billed part of this delivery.
     *
     * @return Collection<int, SalesInvoiceLine>
     */
    public function billingLines(Delivery $delivery): Collection
    {
        return SalesInvoiceLine::query()
            ->whereIn('delivery_line_id', $delivery->lines->pluck('id'))
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', $this->liveInvoiceStatuses()))
            ->with(['invoice:id,invoice_number,invoice_date,status'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Delivered lines this customer may still be invoiced for.
     *
     * @return list<array<string, mixed>>
     */
    public function billableLinesFor(int $customerId): array
    {
        $deliveries = Delivery::query()
            ->where('customer_id', $customerId)
            ->where('status', DeliveryStatus::Posted)
            ->with(['lines.product:id,code,name'])
            ->orderByDesc('delivery_date')
            ->orderByDesc('id')
            ->get();

        $rows = [];

        foreach ($deliveries as $delivery) {
            if (! $this->isBillable($delivery)) {
                continue;
            }

            foreach ($delivery->lines as $line) {
                $remaining = $this->remaining($line);

                if (! DecimalMath::isPositive($remaining)) {
                    continue;
                }

                $rows[] = [
                    'delivery_line_id' => $line->id,
                    'delivery_id' => $delivery->id,
                    'delivery_number' => $delivery->delivery_number,
                    'delivery_date' => $delivery->delivery_date?->toDateString(),
                    'product_id' => $line->product_id,
                    'product_code' => $line->product?->code,
                    'product_name' => $line->product?->name,
                    'original_value' => $this->originalValue($line),
                    'billed_amount' => $this->billedAmount($line),
                    'remaining_amount' => $remaining,
                ];
            }
        }

        return $rows;
    }

    /**
     * One row per accounting-enabled delivery: value, invoiced, remaining and a status.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     value_total: string,
     *     billed_total: string,
     *     remaining_total: string
     * }
     */
    public function report(): array
    {
        $rows = [];
        $value = DecimalMath::zero();
        $billed = DecimalMath::zero();

        $deliveries = Delivery::query()
            ->with(['customer:id,code,name', 'lines'])
            ->orderByDesc('delivery_date')
            ->orderByDesc('id')
            ->get();

        foreach ($deliveries as $delivery) {
            if (! $this->isBillable($delivery)) {
                // Never reached the ledger, or has been reversed: nothing to bill or report.
                continue;
            }

            $deliveryValue = $this->deliveryValue($delivery);
            $deliveryBilled = $this->deliveryBilled($delivery);
            $remaining = DecimalMath::cost(DecimalMath::sub($deliveryValue, $deliveryBilled));

            $value = DecimalMath::add($value, $deliveryValue);
            $billed = DecimalMath::add($billed, $deliveryBilled);

            $rows[] = [
                'delivery_id' => $delivery->id,
                'delivery_number' => $delivery->delivery_number,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'customer_code' => $delivery->customer?->code,
                'customer_name' => $delivery->customer?->name,
                'sales_value' => $deliveryValue,
                'billed_amount' => $deliveryBilled,
                'remaining_amount' => $remaining,
                'status' => $this->statusFor($deliveryBilled, $remaining),
            ];
        }

        $value = DecimalMath::cost($value);
        $billed = DecimalMath::cost($billed);

        return [
            'rows' => $rows,
            'value_total' => $value,
            'billed_total' => $billed,
            'remaining_total' => DecimalMath::cost(DecimalMath::sub($value, $billed)),
        ];
    }

    /**
     * @return list<string>
     */
    private function liveInvoiceStatuses(): array
    {
        return [
            SalesInvoiceStatus::Posted->value,
            SalesInvoiceStatus::PartiallyPaid->value,
            SalesInvoiceStatus::Paid->value,
        ];
    }

    private function statusFor(string $billed, string $remaining): string
    {
        if (DecimalMath::isZero($billed)) {
            return 'UNBILLED';
        }

        return DecimalMath::isZero($remaining) ? 'FULLY_BILLED' : 'PARTIALLY_BILLED';
    }
}
