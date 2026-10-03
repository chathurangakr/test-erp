<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\GenerateJournalReference;
use App\Actions\Accounting\PostJournalEntry;
use App\Enums\CustomerReceiptStatus;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a customer receipt.
 *
 *   Dr Cash / Bank
 *       Cr Trade Receivables
 *
 * **No revenue is recognised** — the invoice already did that — and no inventory or cost of
 * sales is touched. A receipt only settles what is owed.
 *
 * Version 1 has no deposits or unallocated receipts, so every rupee must be allocated to an
 * invoice and the allocations must total the receipt **exactly**. The invoices are locked
 * FOR UPDATE while their outstanding balances are checked, so two receipts posted at once
 * cannot both settle the same balance.
 */
class PostCustomerReceipt
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly AccountsReceivableService $receivables,
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
        private readonly RefreshSalesInvoiceStatus $refreshStatus,
    ) {}

    public function handle(CustomerReceipt $receipt, ?int $userId = null): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): CustomerReceipt {
            $locked = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $period = $this->resolvePeriod($locked);
            $account = $this->resolveReceiptAccount($locked);

            $allocations = $locked->allocations()->orderBy('id')->get();
            $invoices = $this->validateAllocations($locked, $allocations);

            $entry = JournalEntry::create([
                'journal_number' => $this->references->forJournalEntry($locked->receipt_date),
                'journal_date' => $locked->receipt_date->toDateString(),
                'fiscal_period_id' => $period->id,
                'source_type' => CustomerReceipt::class,
                'source_id' => $locked->getKey(),
                'description' => RecordText::of('Customer receipt :reference from :customer', [
                    'reference' => $locked->receipt_number,
                    'customer' => $locked->customer?->code,
                ]),
                'status' => JournalEntryStatus::Draft,
                'created_by' => $userId ?? auth()->id(),
            ]);

            $entry->lines()->create([
                'account_id' => $account->id,
                'description' => $locked->reference,
                'debit' => DecimalMath::cost(DecimalMath::of($locked->amount)),
                'credit' => DecimalMath::cost('0'),
            ]);

            $entry->lines()->create([
                'account_id' => $this->policy->tradeReceivablesAccount()->id,
                'description' => RecordText::of('Settled by :customer', ['customer' => $locked->customer?->code ?? RecordText::of('customer')]),
                'debit' => DecimalMath::cost('0'),
                'credit' => DecimalMath::cost(DecimalMath::of($locked->amount)),
            ]);

            $posted = $this->post->handle($entry->refresh(), $userId);

            $locked->forceFill([
                'fiscal_period_id' => $period->id,
                'status' => CustomerReceiptStatus::Posted,
                'journal_entry_id' => $posted->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            // Each settled invoice's status now follows from what it is still owed.
            foreach ($invoices as $invoice) {
                $this->refreshStatus->handle($invoice->refresh());
            }

            return $locked->refresh()->load(['allocations.invoice', 'journalEntry.lines']);
        });
    }

    private function assertPostable(CustomerReceipt $receipt): void
    {
        if (! $receipt->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft customer receipt can be posted. :reference is :status.', [
                    'reference' => $receipt->receipt_number,
                    'status' => $receipt->status->label(),
                ]),
            ]);
        }

        if ($receipt->journal_entry_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Customer receipt :reference has already been posted.', [
                    'reference' => $receipt->receipt_number,
                ]),
            ]);
        }

        if (! $receipt->customer?->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => __('Receipts cannot be recorded against an inactive customer.'),
            ]);
        }

        if (! DecimalMath::isPositive(DecimalMath::of($receipt->amount))) {
            throw ValidationException::withMessages([
                'amount' => __('The receipt amount must be greater than zero.'),
            ]);
        }
    }

    private function resolvePeriod(CustomerReceipt $receipt): FiscalPeriod
    {
        $period = FiscalPeriod::openFor($receipt->receipt_date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'receipt_date' => __('There is no open fiscal period covering :date, so this receipt cannot be posted.', [
                    'date' => $receipt->receipt_date->toDateString(),
                ]),
            ]);
        }

        return $period;
    }

    private function resolveReceiptAccount(CustomerReceipt $receipt): Account
    {
        $account = Account::find($receipt->receipt_account_id);

        if ($account === null) {
            throw ValidationException::withMessages([
                'receipt_account_id' => __('The selected receipt account does not exist.'),
            ]);
        }

        $this->policy->assertReceiptAccount($account, $receipt->payment_method);
        $this->policy->assertChequeDetails($receipt->payment_method, $receipt->cheque_number, $receipt->cheque_date, $receipt->cheque_bank);

        return $account;
    }

    /**
     * Check every allocation, and return the invoices they settle.
     *
     * @param  Collection<int, CustomerReceiptAllocation>  $allocations
     * @return Collection<int, SalesInvoice>
     */
    private function validateAllocations(CustomerReceipt $receipt, Collection $allocations): Collection
    {
        if ($allocations->isEmpty()) {
            throw ValidationException::withMessages([
                'allocations' => __('A receipt must be allocated to at least one invoice. Receipts on account are not supported.'),
            ]);
        }

        $invoices = collect();
        $total = DecimalMath::zero();

        foreach ($allocations as $index => $allocation) {
            $amount = DecimalMath::cost(DecimalMath::of($allocation->amount));

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => __('Every allocation must be greater than zero.'),
                ]);
            }

            // Locked while its outstanding balance is read.
            $invoice = SalesInvoice::query()
                ->whereKey($allocation->sales_invoice_id)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.sales_invoice_id" => __('The selected invoice does not exist.'),
                ]);
            }

            if ($invoice->customer_id !== $receipt->customer_id) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.sales_invoice_id" => __('Invoice :reference belongs to a different customer.', [
                        'reference' => $invoice->invoice_number,
                    ]),
                ]);
            }

            if (! $invoice->status->isOutstanding()) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.sales_invoice_id" => __('Invoice :reference is :status and cannot receive a payment.', [
                        'reference' => $invoice->invoice_number,
                        'status' => $invoice->status->label(),
                    ]),
                ]);
            }

            $outstanding = $this->receivables->outstanding($invoice);

            if (DecimalMath::compare($amount, $outstanding) > 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => __('Invoice :reference has only :outstanding outstanding; this allocation is :amount.', [
                        'reference' => $invoice->invoice_number,
                        'outstanding' => $outstanding,
                        'amount' => $amount,
                    ]),
                ]);
            }

            $total = DecimalMath::add($total, $amount);
            $invoices->push($invoice);
        }

        $total = DecimalMath::cost($total);
        $amount = DecimalMath::cost(DecimalMath::of($receipt->amount));

        if (DecimalMath::compare($total, $amount) !== 0) {
            throw ValidationException::withMessages([
                'allocations' => __('Allocations total :allocated but the receipt is :amount. Every rupee must be allocated to an invoice.', [
                    'allocated' => $total,
                    'amount' => $amount,
                ]),
            ]);
        }

        return $invoices;
    }
}
