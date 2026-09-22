<?php

namespace App\Actions\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionLine;
use App\Models\JournalEntry;
use App\Models\ProductionOrder;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Turns a posted stock movement into a journal entry.
 *
 * One rule governs every amount here: **the inventory ledger's `total_cost` is what reaches
 * the books.** Never `quantity × unit_cost` — a six-decimal rate cannot reproduce a total
 * derived the other way round, and the difference would leave the general ledger and the
 * inventory ledger permanently out of step. See docs/INVENTORY_RULES.md §8.1.
 *
 * The three events:
 *
 *   GOODS RECEIPT   Dr product inventory        Cr Goods Received Not Invoiced
 *   DELIVERY        Dr product cost of sales    Cr product inventory
 *   PRODUCTION      Dr output inventory         Cr input inventory
 *
 * A **warehouse transfer posts nothing**: the company owns the same product, valued the
 * same, in the same account, before and after.
 *
 * Revenue is deliberately absent from the delivery journal. A delivery is a physical issue;
 * revenue and receivables belong to the sales invoice, which is a later phase.
 *
 * This is not a second accounting engine — it builds a draft and hands it to the ordinary
 * PostJournalEntry, so a generated journal is held to every rule a manual one is: balanced
 * exactly, active accounts, open period, immutable once posted.
 */
class RecordOperationalJournal
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
        private readonly ReverseJournalEntry $reverseJournal,
    ) {}

    /**
     * Dr inventory, Cr GRNI — at the exact landed value that reached stock.
     */
    public function forGoodsReceipt(GoodsReceipt $receipt, InventoryTransaction $transaction, ?int $userId = null): ?JournalEntry
    {
        if (! $this->policy->appliesTo($receipt->receipt_date)) {
            return null;
        }

        $lines = [];
        $total = DecimalMath::zero();

        foreach ($this->linesOf($transaction) as $line) {
            $amount = DecimalMath::cost(DecimalMath::of($line->total_cost));
            $total = DecimalMath::add($total, $amount);

            $lines[] = [
                'account_id' => $this->policy->inventoryAccountFor($line->product)->id,
                'description' => __('Received :product', ['product' => $line->product->code]),
                'debit' => $amount,
                'credit' => DecimalMath::cost('0'),
            ];
        }

        $lines[] = [
            'account_id' => $this->policy->grniAccount()->id,
            'description' => __('Goods received not invoiced — :supplier', [
                'supplier' => $receipt->supplier?->code ?? __('supplier'),
            ]),
            'debit' => DecimalMath::cost('0'),
            'credit' => DecimalMath::cost($total),
        ];

        return $this->record(
            $receipt,
            $receipt->receipt_date,
            __('Goods receipt :reference', ['reference' => $receipt->grn_number]),
            $lines,
            $userId,
        );
    }

    /**
     * Dr cost of sales, Cr inventory — at the exact value the engine charged the issue.
     *
     * The selling price has no influence here at all; it does not appear in this journal.
     */
    public function forDelivery(Delivery $delivery, InventoryTransaction $transaction, ?int $userId = null): ?JournalEntry
    {
        if (! $this->policy->appliesTo($delivery->delivery_date)) {
            return null;
        }

        $lines = [];

        foreach ($this->linesOf($transaction) as $line) {
            $amount = DecimalMath::cost(DecimalMath::of($line->total_cost));

            $lines[] = [
                'account_id' => $this->policy->cogsAccountFor($line->product)->id,
                'description' => __('Cost of :product sold', ['product' => $line->product->code]),
                'debit' => $amount,
                'credit' => DecimalMath::cost('0'),
            ];

            $lines[] = [
                'account_id' => $this->policy->inventoryAccountFor($line->product)->id,
                'description' => __('Issued :product', ['product' => $line->product->code]),
                'debit' => DecimalMath::cost('0'),
                'credit' => $amount,
            ];
        }

        return $this->record(
            $delivery,
            $delivery->delivery_date,
            __('Delivery :reference — cost of goods sold', ['reference' => $delivery->delivery_number]),
            $lines,
            $userId,
        );
    }

    /**
     * Dr output inventory, Cr input inventory — value moving between asset accounts.
     *
     * Accounting does not recalculate the 85/15, 30/30/40 or 75/25 split. Those percentages
     * decided the inventory valuation upstream; Finance simply records the figures the
     * ledger already holds, which is why debits and credits agree exactly.
     */
    public function forProduction(
        ProductionOrder $order,
        InventoryTransaction $consumption,
        InventoryTransaction $output,
        ?int $userId = null,
    ): ?JournalEntry {
        if (! $this->policy->appliesTo($order->production_date)) {
            return null;
        }

        $lines = [];

        foreach ($this->linesOf($output) as $line) {
            $lines[] = [
                'account_id' => $this->policy->inventoryAccountFor($line->product)->id,
                'description' => __('Produced :product', ['product' => $line->product->code]),
                'debit' => DecimalMath::cost(DecimalMath::of($line->total_cost)),
                'credit' => DecimalMath::cost('0'),
            ];
        }

        foreach ($this->linesOf($consumption) as $line) {
            $lines[] = [
                'account_id' => $this->policy->inventoryAccountFor($line->product)->id,
                'description' => __('Consumed :product', ['product' => $line->product->code]),
                'debit' => DecimalMath::cost('0'),
                'credit' => DecimalMath::cost(DecimalMath::of($line->total_cost)),
            ];
        }

        return $this->record(
            $order,
            $order->production_date,
            __(':type production :reference', [
                'type' => $order->production_type->label(),
                'reference' => $order->production_number,
            ]),
            $lines,
            $userId,
        );
    }

    /**
     * Reverse the journal a source document generated, if it generated one.
     *
     * Returns null when the document predates the cutover and therefore never had a journal
     * — reversing stock that was never accounted for is correct, not an error.
     */
    public function reverseFor(Model $source, ?int $userId = null): ?JournalEntry
    {
        $original = $this->originalJournalFor($source);

        if ($original === null) {
            return null;
        }

        if ($original->isReversed()) {
            throw ValidationException::withMessages([
                'status' => __('The accounting entry :reference for this document has already been reversed.', [
                    'reference' => $original->journal_number,
                ]),
            ]);
        }

        // Delegates to the ordinary reversal: every line mirrored at its EXACT original
        // amount. Nothing is recalculated, so the pair sums to nothing.
        return $this->reverseJournal->handle($original, $userId);
    }

    /**
     * The one original journal a source document generated, if any.
     *
     * Excludes reversals, which carry the same source so that both halves of a correction
     * are findable from the document.
     */
    public function originalJournalFor(Model $source): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->whereNull('reversal_of_id')
            ->whereIn('status', [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value])
            ->orderBy('id')
            ->first();
    }

    /**
     * Build the draft and post it through the ordinary engine.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function record(Model $source, mixed $date, string $description, array $lines, ?int $userId): JournalEntry
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => __('There is nothing to account for on this document.'),
            ]);
        }

        // At most one original journal per posted source event. A retry must not quietly
        // double the books.
        if ($this->originalJournalFor($source) !== null) {
            throw ValidationException::withMessages([
                'status' => __('This document has already been accounted for.'),
            ]);
        }

        $period = $this->policy->periodFor($date, $description);
        $journalDate = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        $entry = JournalEntry::create([
            // Numbered by the journal's own date, so the reference and the entry agree.
            'journal_number' => $this->references->forJournalEntry($journalDate),
            'journal_date' => $journalDate->toDateString(),
            'fiscal_period_id' => $period->id,
            // Naming the source is what allows this entry to touch control accounts, and
            // what makes the journal traceable back to the document that caused it.
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'description' => $description,
            'status' => JournalEntryStatus::Draft,
            'created_by' => $userId ?? auth()->id(),
        ]);

        foreach ($lines as $line) {
            $entry->lines()->create($line);
        }

        return $this->post->handle($entry->refresh(), $userId);
    }

    /**
     * The ledger lines of a transaction, with their products loaded.
     *
     * @return Collection<int, InventoryTransactionLine>
     */
    private function linesOf(InventoryTransaction $transaction): Collection
    {
        return $transaction->lines()->with('product')->orderBy('id')->get();
    }
}
