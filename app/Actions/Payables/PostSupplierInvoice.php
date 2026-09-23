<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\GenerateJournalReference;
use App\Actions\Accounting\PostJournalEntry;
use App\Enums\JournalEntryStatus;
use App\Enums\SupplierInvoiceLineType;
use App\Enums\SupplierInvoiceStatus;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Accounting\GrniReconciliationService;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a supplier invoice into the books.
 *
 *   GRNI_MATCH line      Dr Goods Received Not Invoiced   (clears part of a receipt's accrual)
 *   DIRECT_EXPENSE line  Dr the chosen expense account
 *                            Cr Trade Payables            (always, from configuration)
 *
 * **Inventory is not touched.** The goods receipt established what the stock is worth; the
 * invoice records who is owed for it. Posting an invoice changes no quantity, no ledger row,
 * no weighted average and no production cost.
 *
 * A receipt's accrual may be cleared by **several invoices from several suppliers** — goods,
 * transport and loading are commonly three different companies — so matching is per-amount
 * and partial. The receipts being matched are locked FOR UPDATE, so two invoices posted at
 * once cannot both consume the same remaining accrual.
 *
 * No purchase-price variance is posted. If invoices never fully clear a receipt, the
 * remainder stays visible on the GRNI reconciliation rather than being written off somewhere
 * nobody asked for. See docs/ACCOUNTING_RULES.md §17.
 */
class PostSupplierInvoice
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly GrniReconciliationService $grni,
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
    ) {}

    public function handle(SupplierInvoice $invoice, ?int $userId = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): SupplierInvoice {
            $locked = SupplierInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $period = $this->resolvePeriod($locked);
            $lines = $locked->lines()->orderBy('id')->get();

            $this->assertLinesAreValid($lines);

            $payables = $this->policy->tradePayablesAccount();
            $journalLines = $this->buildJournalLines($lines, $payables, $locked);

            $entry = JournalEntry::create([
                'journal_number' => $this->references->forJournalEntry($locked->invoice_date),
                'journal_date' => $locked->invoice_date->toDateString(),
                'fiscal_period_id' => $period->id,
                // Naming the source is what lets this entry reach the GRNI and Trade
                // Payables control accounts, and what makes it traceable to the invoice.
                'source_type' => SupplierInvoice::class,
                'source_id' => $locked->getKey(),
                'description' => __('Supplier invoice :internal (:supplier :number)', [
                    'internal' => $locked->internal_invoice_number,
                    'supplier' => $locked->supplier?->code,
                    'number' => $locked->supplier_invoice_number,
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
                'status' => SupplierInvoiceStatus::Posted,
                'journal_entry_id' => $posted->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            return $locked->refresh()->load(['lines', 'journalEntry.lines']);
        });
    }

    private function assertPostable(SupplierInvoice $invoice): void
    {
        if (! $invoice->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft supplier invoice can be posted. :reference is :status.', [
                    'reference' => $invoice->internal_invoice_number,
                    'status' => $invoice->status->label(),
                ]),
            ]);
        }

        if ($invoice->journal_entry_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Supplier invoice :reference has already been posted.', [
                    'reference' => $invoice->internal_invoice_number,
                ]),
            ]);
        }

        if (! $invoice->supplier?->is_active) {
            throw ValidationException::withMessages([
                'supplier_id' => __('Invoices cannot be recorded against an inactive supplier.'),
            ]);
        }

        if ($invoice->due_date->lessThan($invoice->invoice_date)) {
            throw ValidationException::withMessages([
                'due_date' => __('The due date cannot fall before the invoice date.'),
            ]);
        }
    }

    /**
     * A supplier invoice is a financial document, so it always needs an open period
     * containing its date — the operational accounting cutover does not apply to it.
     */
    private function resolvePeriod(SupplierInvoice $invoice): FiscalPeriod
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
     * @param  Collection<int, SupplierInvoiceLine>  $lines
     */
    private function assertLinesAreValid(Collection $lines): void
    {
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('A supplier invoice must have at least one line.'),
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
     * Build the journal, validating each line as it goes.
     *
     * @param  Collection<int, SupplierInvoiceLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function buildJournalLines(Collection $lines, Account $payables, SupplierInvoice $invoice): array
    {
        $journalLines = [];
        $total = DecimalMath::zero();

        // Accumulated per receipt, so several lines on one invoice against the same receipt
        // are checked against the remaining accrual together rather than one at a time.
        $claimedByReceipt = [];

        foreach ($lines as $index => $line) {
            $amount = DecimalMath::cost(DecimalMath::of($line->amount));
            $total = DecimalMath::add($total, $amount);

            if ($line->line_type === SupplierInvoiceLineType::GrniMatch) {
                $journalLines[] = $this->grniLine($line, $index, $amount, $claimedByReceipt);

                continue;
            }

            $journalLines[] = $this->directExpenseLine($line, $index, $amount);
        }

        $journalLines[] = [
            'account_id' => $payables->id,
            'description' => __('Payable to :supplier', ['supplier' => $invoice->supplier?->code ?? __('supplier')]),
            'debit' => DecimalMath::cost('0'),
            'credit' => DecimalMath::cost($total),
        ];

        return $journalLines;
    }

    /**
     * A line clearing part of a receipt's accrual.
     *
     * @param  array<int, string>  $claimedByReceipt
     * @return array<string, mixed>
     */
    private function grniLine(SupplierInvoiceLine $line, int $index, string $amount, array &$claimedByReceipt): array
    {
        if ($line->goods_receipt_id === null) {
            throw ValidationException::withMessages([
                "lines.{$index}.goods_receipt_id" => __('Select the goods receipt this line is invoicing.'),
            ]);
        }

        // Locked for the rest of this transaction, so concurrent invoices cannot both
        // consume the same remaining accrual.
        $receipt = GoodsReceipt::query()
            ->whereKey($line->goods_receipt_id)
            ->lockForUpdate()
            ->first();

        if ($receipt === null) {
            throw ValidationException::withMessages([
                "lines.{$index}.goods_receipt_id" => __('The selected goods receipt does not exist.'),
            ]);
        }

        if (! $this->grni->isMatchable($receipt)) {
            throw ValidationException::withMessages([
                "lines.{$index}.goods_receipt_id" => __('Goods receipt :reference raised no accounting accrual — it predates accounting, or it has been reversed — so nothing can be invoiced against it.', [
                    'reference' => $receipt->grn_number,
                ]),
            ]);
        }

        $alreadyClaimed = $claimedByReceipt[$receipt->id] ?? DecimalMath::zero();
        $remaining = DecimalMath::sub($this->grni->remaining($receipt), $alreadyClaimed);

        if (DecimalMath::compare($amount, $remaining) > 0) {
            throw ValidationException::withMessages([
                "lines.{$index}.amount" => __('Only :remaining of goods receipt :reference is still uninvoiced; this line claims :amount.', [
                    'remaining' => DecimalMath::cost($remaining),
                    'reference' => $receipt->grn_number,
                    'amount' => $amount,
                ]),
            ]);
        }

        $claimedByReceipt[$receipt->id] = DecimalMath::add($alreadyClaimed, $amount);

        return [
            'account_id' => $this->policy->grniAccount()->id,
            'description' => $line->description ?? __('Invoiced against :reference', ['reference' => $receipt->grn_number]),
            'debit' => $amount,
            'credit' => DecimalMath::cost('0'),
        ];
    }

    /**
     * A line charging an expense account directly.
     *
     * @return array<string, mixed>
     */
    private function directExpenseLine(SupplierInvoiceLine $line, int $index, string $amount): array
    {
        if ($line->debit_account_id === null) {
            throw ValidationException::withMessages([
                "lines.{$index}.debit_account_id" => __('Select the expense account for this line.'),
            ]);
        }

        $account = Account::find($line->debit_account_id);

        if ($account === null) {
            throw ValidationException::withMessages([
                "lines.{$index}.debit_account_id" => __('The selected account does not exist.'),
            ]);
        }

        $this->policy->assertDirectExpenseAccount($account);

        return [
            'account_id' => $account->id,
            'description' => $line->description,
            'debit' => $amount,
            'credit' => DecimalMath::cost('0'),
        ];
    }
}
