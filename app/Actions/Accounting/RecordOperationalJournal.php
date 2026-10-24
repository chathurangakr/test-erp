<?php

namespace App\Actions\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\CustomerBagMovement;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionLine;
use App\Models\JournalEntry;
use App\Models\ProductionOrder;
use App\Models\StockDamage;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use App\Support\RecordText;
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
 *   STOCK DAMAGE    Dr stock damage and losses  Cr product inventory
 *   BAGS LOST       Dr stock damage and losses  Cr product inventory
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
                'description' => RecordText::of('Received :product', ['product' => $line->product->code]),
                'debit' => $amount,
                'credit' => DecimalMath::cost('0'),
            ];
        }

        $lines[] = [
            'account_id' => $this->policy->grniAccount()->id,
            'description' => RecordText::of('Goods received not invoiced — :supplier', [
                'supplier' => $receipt->supplier?->code ?? RecordText::of('supplier'),
            ]),
            'debit' => DecimalMath::cost('0'),
            'credit' => DecimalMath::cost($total),
        ];

        return $this->record(
            $receipt,
            $receipt->receipt_date,
            RecordText::of('Goods receipt :reference', ['reference' => $receipt->grn_number]),
            $lines,
            $userId,
        );
    }

    /**
     * Dr cost of sales, Cr inventory — at the exact value the engine charged the issue.
     *
     * The selling price has no influence here at all; it does not appear in this journal.
     */
    public function forDelivery(
        Delivery $delivery,
        InventoryTransaction $transaction,
        ?int $userId = null,
        ?InventoryTransaction $backflush = null,
    ): ?JournalEntry {
        if (! $this->policy->appliesTo($delivery->delivery_date)) {
            return null;
        }

        $lines = [];

        foreach ($this->linesOf($transaction) as $line) {
            $amount = DecimalMath::cost(DecimalMath::of($line->total_cost));

            $lines[] = [
                'account_id' => $this->policy->cogsAccountFor($line->product)->id,
                'description' => RecordText::of('Cost of :product sold', ['product' => $line->product->code]),
                'debit' => $amount,
                'credit' => DecimalMath::cost('0'),
            ];

            $lines[] = [
                'account_id' => $this->policy->inventoryAccountFor($line->product)->id,
                'description' => RecordText::of('Issued :product', ['product' => $line->product->code]),
                'debit' => DecimalMath::cost('0'),
                'credit' => $amount,
            ];
        }

        if ($backflush !== null) {
            // Each backflush ledger line belongs, in order, to the delivery line that needed
            // it. Its cost is part of what THAT product cost to sell, so it lands in the
            // delivered product's cost of sales — not in the raw material's.
            $owners = $delivery->lines()->with('product')->whereNotNull('backflush_product_id')->orderBy('id')->get()->values();

            foreach ($this->linesOf($backflush)->values() as $position => $line) {
                $owner = $owners[$position] ?? null;

                if ($owner === null) {
                    throw ValidationException::withMessages([
                        'lines' => __('The backflushed raw material could not be matched to its delivery line.'),
                    ]);
                }

                $amount = DecimalMath::cost(DecimalMath::of($line->total_cost));

                $lines[] = [
                    'account_id' => $this->policy->cogsAccountFor($owner->product)->id,
                    'description' => RecordText::of(':input used in :product sold', [
                        'input' => $line->product->code,
                        'product' => $owner->product->code,
                    ]),
                    'debit' => $amount,
                    'credit' => DecimalMath::cost('0'),
                ];

                $lines[] = [
                    'account_id' => $this->policy->inventoryAccountFor($line->product)->id,
                    'description' => RecordText::of('Backflushed :product', ['product' => $line->product->code]),
                    'debit' => DecimalMath::cost('0'),
                    'credit' => $amount,
                ];
            }
        }

        // A line can be worth nothing — chips whose raw husk is only now being costed — and a
        // journal line is a debit or a credit, never neither.
        $lines = array_values(array_filter(
            $lines,
            fn (array $line): bool => DecimalMath::isPositive($line['debit']) || DecimalMath::isPositive($line['credit']),
        ));

        if ($lines === []) {
            return null;
        }

        return $this->record(
            $delivery,
            $delivery->delivery_date,
            RecordText::of('Delivery :reference — cost of goods sold', ['reference' => $delivery->delivery_number]),
            $lines,
            $userId,
        );
    }

    /**
     * Production: Dr each output's inventory account, Cr the input's — and, when the run
     * carried labour, Cr the production-labour-absorbed account for it.
     *
     * Every figure is read from the ledger lines and the order exactly as posted, so the entry
     * balances by construction: outputs = consumed value + labour. Zero-value lines are left
     * out (a journal line is a debit or a credit, never neither), and a run that moved no value
     * at all — husk chipping, whose raw husk is backflushed at delivery — records no journal.
     */
    public function forProduction(
        ProductionOrder $order,
        ?InventoryTransaction $consumption,
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
                'description' => RecordText::of('Produced :product', ['product' => $line->product->code]),
                'debit' => DecimalMath::cost(DecimalMath::of($line->total_cost)),
                'credit' => DecimalMath::cost('0'),
            ];
        }

        if ($consumption !== null) {
            foreach ($this->linesOf($consumption) as $line) {
                $lines[] = [
                    'account_id' => $this->policy->inventoryAccountFor($line->product)->id,
                    'description' => RecordText::of('Consumed :product', ['product' => $line->product->code]),
                    'debit' => DecimalMath::cost('0'),
                    'credit' => DecimalMath::cost(DecimalMath::of($line->total_cost)),
                ];
            }
        }

        $labour = DecimalMath::cost(DecimalMath::of($order->labour_cost ?? '0'));

        if (DecimalMath::isPositive($labour)) {
            $lines[] = [
                'account_id' => $this->policy->productionLabourAccount()->id,
                'description' => RecordText::of('Labour absorbed into :reference', ['reference' => $order->production_number]),
                'debit' => DecimalMath::cost('0'),
                'credit' => $labour,
            ];
        }

        $lines = array_values(array_filter(
            $lines,
            fn (array $line): bool => DecimalMath::isPositive($line['debit']) || DecimalMath::isPositive($line['credit']),
        ));

        if ($lines === []) {
            return null;
        }

        return $this->record(
            $order,
            $order->production_date,
            RecordText::of(':type production :reference', [
                'type' => $order->production_type,
                'reference' => $order->production_number,
            ]),
            $lines,
            $userId,
        );
    }

    /**
     * Damaged stock written off: Dr stock damage and losses, Cr each product's inventory — at
     * the exact value the engine charged the issue. A write-off that moved no value (stock
     * still carrying no cost) records no journal.
     */
    public function forStockDamage(StockDamage $damage, InventoryTransaction $transaction, ?int $userId = null): ?JournalEntry
    {
        if (! $this->policy->appliesTo($damage->damage_date)) {
            return null;
        }

        $lines = $this->writeOffLines($transaction);

        if ($lines === []) {
            return null;
        }

        return $this->record(
            $damage,
            $damage->damage_date,
            RecordText::of('Stock damage :reference — :reason', [
                'reference' => $damage->damage_number,
                'reason' => $damage->reason,
            ]),
            $lines,
            $userId,
        );
    }

    /**
     * Returnable bags a customer will not bring back: the same write-off as damaged stock,
     * charged to the stock-loss expense at the value the ledger charged.
     */
    public function forLostBags(CustomerBagMovement $movement, InventoryTransaction $transaction, ?int $userId = null): ?JournalEntry
    {
        if (! $this->policy->appliesTo($movement->movement_date)) {
            return null;
        }

        $lines = $this->writeOffLines($transaction);

        if ($lines === []) {
            return null;
        }

        return $this->record(
            $movement,
            $movement->movement_date,
            RecordText::of('Bags lost by :customer — :reference', [
                'customer' => $movement->customer?->code ?? RecordText::of('customer'),
                'reference' => $movement->movement_number,
            ]),
            $lines,
            $userId,
        );
    }

    /**
     * Dr stock damage and losses, Cr each product's inventory, for every valued ledger line.
     *
     * @return list<array<string, mixed>>
     */
    private function writeOffLines(InventoryTransaction $transaction): array
    {
        $lines = [];

        foreach ($this->linesOf($transaction) as $line) {
            $amount = DecimalMath::cost(DecimalMath::of($line->total_cost));

            if (! DecimalMath::isPositive($amount)) {
                continue;
            }

            $lines[] = [
                'account_id' => $this->policy->stockLossAccount()->id,
                'description' => RecordText::of('Damaged :product written off', ['product' => $line->product->code]),
                'debit' => $amount,
                'credit' => DecimalMath::cost('0'),
            ];

            $lines[] = [
                'account_id' => $this->policy->inventoryAccountFor($line->product)->id,
                'description' => RecordText::of('Written off :product', ['product' => $line->product->code]),
                'debit' => DecimalMath::cost('0'),
                'credit' => $amount,
            ];
        }

        return $lines;
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
