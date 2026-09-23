<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\GenerateJournalReference;
use App\Actions\Accounting\PostJournalEntry;
use App\Enums\JournalEntryStatus;
use App\Enums\SalesInvoiceStatus;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Accounting\SalesBillingReconciliationService;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a sales invoice — the moment revenue is recognised.
 *
 *   Dr Trade Receivables
 *       Cr the revenue account of each line's product
 *
 * **No cost of sales and no inventory.** The delivery already posted
 * `Dr cost of sales / Cr inventory` when the goods went out; this document records what the
 * customer owes for them. Posting an invoice moves no stock, changes no weighted average and
 * writes no second COGS entry.
 *
 * **The amount billed comes from the delivery line's stored `sales_value`**, never
 * recalculated. That is what carries the dual-unit Raw Shell case through untouched — the
 * line was priced per measured KG at delivery, and AR introduces no second conversion.
 *
 * A delivered line may be billed by several invoices and an invoice may span several
 * deliveries, so matching is per-amount and partial. The delivery lines are locked FOR UPDATE
 * while their remaining value is checked, so two invoices posted at once cannot both bill it.
 *
 * See docs/ACCOUNTING_RULES.md §20.
 */
class PostSalesInvoice
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly SalesBillingReconciliationService $billing,
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
    ) {}

    public function handle(SalesInvoice $invoice, ?int $userId = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $locked = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $period = $this->resolvePeriod($locked);
            $lines = $locked->lines()->orderBy('id')->get();

            $this->assertLinesAreValid($lines);

            $journalLines = $this->buildJournalLines($locked, $lines);

            $entry = JournalEntry::create([
                'journal_number' => $this->references->forJournalEntry($locked->invoice_date),
                'journal_date' => $locked->invoice_date->toDateString(),
                'fiscal_period_id' => $period->id,
                // Naming the source is what lets this entry reach the Trade Receivables
                // control account, and what makes it traceable to the invoice.
                'source_type' => SalesInvoice::class,
                'source_id' => $locked->getKey(),
                'description' => __('Sales invoice :reference to :customer', [
                    'reference' => $locked->invoice_number,
                    'customer' => $locked->customer?->code,
                ]),
                'status' => JournalEntryStatus::Draft,
                'created_by' => $userId ?? auth()->id(),
            ]);

            foreach ($journalLines as $line) {
                $entry->lines()->create($line);
            }

            $posted = $this->post->handle($entry->refresh(), $userId);

            $locked->forceFill([
                'fiscal_period_id' => $period->id,
                'status' => SalesInvoiceStatus::Posted,
                'journal_entry_id' => $posted->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            return $locked->refresh()->load(['lines', 'journalEntry.lines']);
        });
    }

    private function assertPostable(SalesInvoice $invoice): void
    {
        if (! $invoice->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft sales invoice can be posted. :reference is :status.', [
                    'reference' => $invoice->invoice_number,
                    'status' => $invoice->status->label(),
                ]),
            ]);
        }

        if ($invoice->journal_entry_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Sales invoice :reference has already been posted.', [
                    'reference' => $invoice->invoice_number,
                ]),
            ]);
        }

        if (! $invoice->customer?->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => __('Invoices cannot be raised against an inactive customer.'),
            ]);
        }

        if ($invoice->due_date->lessThan($invoice->invoice_date)) {
            throw ValidationException::withMessages([
                'due_date' => __('The due date cannot fall before the invoice date.'),
            ]);
        }
    }

    /**
     * A sales invoice is a financial document, so it always needs an open period containing
     * its date — the operational accounting cutover does not apply to it.
     */
    private function resolvePeriod(SalesInvoice $invoice): FiscalPeriod
    {
        $period = FiscalPeriod::openFor($invoice->invoice_date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'invoice_date' => __('There is no open fiscal period covering :date, so this invoice cannot be posted.', [
                    'date' => $invoice->invoice_date->toDateString(),
                ]),
            ]);
        }

        return $period;
    }

    /**
     * @param  Collection<int, SalesInvoiceLine>  $lines
     */
    private function assertLinesAreValid(Collection $lines): void
    {
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('A sales invoice must have at least one line.'),
            ]);
        }

        foreach ($lines as $index => $line) {
            if (! DecimalMath::isPositive(DecimalMath::of($line->amount))) {
                throw ValidationException::withMessages([
                    "lines.{$index}.amount" => __('Every invoice line amount must be greater than zero.'),
                ]);
            }
        }
    }

    /**
     * Build the journal, validating each delivered line as it goes.
     *
     * @param  Collection<int, SalesInvoiceLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function buildJournalLines(SalesInvoice $invoice, Collection $lines): array
    {
        $journalLines = [];
        $total = DecimalMath::zero();

        // Accumulated per delivered line, so several invoice lines against the same delivery
        // line are checked against the remaining value together rather than one at a time.
        $claimed = [];

        foreach ($lines as $index => $line) {
            $amount = DecimalMath::cost(DecimalMath::of($line->amount));
            $total = DecimalMath::add($total, $amount);

            $deliveryLine = $this->resolveDeliveryLine($invoice, $line, $index, $amount, $claimed);

            $journalLines[] = [
                'account_id' => $this->policy->revenueAccountFor($deliveryLine->product)->id,
                'description' => $line->description ?? __('Invoiced :product on :reference', [
                    'product' => $deliveryLine->product?->code,
                    'reference' => $deliveryLine->delivery?->delivery_number,
                ]),
                'debit' => DecimalMath::cost('0'),
                'credit' => $amount,
            ];
        }

        // One receivable line for the whole invoice.
        array_unshift($journalLines, [
            'account_id' => $this->policy->tradeReceivablesAccount()->id,
            'description' => __('Receivable from :customer', ['customer' => $invoice->customer?->code ?? __('customer')]),
            'debit' => DecimalMath::cost($total),
            'credit' => DecimalMath::cost('0'),
        ]);

        return $journalLines;
    }

    /**
     * The delivered line this invoice line bills, checked and locked.
     *
     * @param  array<int, string>  $claimed
     */
    private function resolveDeliveryLine(
        SalesInvoice $invoice,
        SalesInvoiceLine $line,
        int $index,
        string $amount,
        array &$claimed,
    ): DeliveryLine {
        // Locked for the rest of this transaction, so concurrent invoices cannot both bill
        // the same remaining value.
        $deliveryLine = DeliveryLine::query()
            ->whereKey($line->delivery_line_id)
            ->lockForUpdate()
            ->with(['delivery', 'product'])
            ->first();

        if ($deliveryLine === null) {
            throw ValidationException::withMessages([
                "lines.{$index}.delivery_line_id" => __('The selected delivery line does not exist.'),
            ]);
        }

        $delivery = $deliveryLine->delivery;

        if ($delivery === null) {
            throw ValidationException::withMessages([
                "lines.{$index}.delivery_line_id" => __('The selected delivery line has no delivery.'),
            ]);
        }

        if ($delivery->customer_id !== $invoice->customer_id) {
            throw ValidationException::withMessages([
                "lines.{$index}.delivery_line_id" => __('Delivery :reference belongs to a different customer.', [
                    'reference' => $delivery->delivery_number,
                ]),
            ]);
        }

        if (! $this->billing->isBillable($delivery)) {
            throw ValidationException::withMessages([
                "lines.{$index}.delivery_line_id" => __('Delivery :reference has no cost of sales in the ledger — it predates accounting, or it has been reversed — so its revenue cannot be recognised.', [
                    'reference' => $delivery->delivery_number,
                ]),
            ]);
        }

        $alreadyClaimed = $claimed[$deliveryLine->id] ?? DecimalMath::zero();
        $remaining = DecimalMath::sub($this->billing->remaining($deliveryLine), $alreadyClaimed);

        if (DecimalMath::compare($amount, $remaining) > 0) {
            throw ValidationException::withMessages([
                "lines.{$index}.amount" => __('Only :remaining of :product on :reference is still unbilled; this line claims :amount.', [
                    'remaining' => DecimalMath::cost($remaining),
                    'product' => $deliveryLine->product?->code,
                    'reference' => $delivery->delivery_number,
                    'amount' => $amount,
                ]),
            ]);
        }

        $claimed[$deliveryLine->id] = DecimalMath::add($alreadyClaimed, $amount);

        return $deliveryLine;
    }
}
