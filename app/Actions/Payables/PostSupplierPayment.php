<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\GenerateJournalReference;
use App\Actions\Accounting\PostJournalEntry;
use App\Enums\JournalEntryStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Accounting\AccountsPayableService;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a supplier payment.
 *
 *   Dr Trade Payables
 *       Cr Cash / Bank
 *
 * The payment **does not touch GRNI**: the accrual was already cleared when the invoice was
 * recorded. Nor does it touch inventory — no stock, no ledger row, no weighted average.
 *
 * Version 1 has no prepayments, so every rupee must be allocated to an invoice and the
 * allocations must total the payment **exactly**. The invoices are locked FOR UPDATE while
 * their outstanding balances are checked, so two payments posted at once cannot both settle
 * the same balance.
 */
class PostSupplierPayment
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly AccountsPayableService $payables,
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
        private readonly RefreshSupplierInvoiceStatus $refreshStatus,
    ) {}

    public function handle(SupplierPayment $payment, ?int $userId = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $userId): SupplierPayment {
            $locked = SupplierPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $period = $this->resolvePeriod($locked);
            $account = $this->resolvePaymentAccount($locked);

            $allocations = $locked->allocations()->orderBy('id')->get();
            $invoices = $this->validateAllocations($locked, $allocations);

            $entry = JournalEntry::create([
                'journal_number' => $this->references->forJournalEntry($locked->payment_date),
                'journal_date' => $locked->payment_date->toDateString(),
                'fiscal_period_id' => $period->id,
                'source_type' => SupplierPayment::class,
                'source_id' => $locked->getKey(),
                'description' => RecordText::of('Supplier payment :reference to :supplier', [
                    'reference' => $locked->payment_number,
                    'supplier' => $locked->supplier?->code,
                ]),
                'status' => JournalEntryStatus::Draft,
                'created_by' => $userId ?? auth()->id(),
            ]);

            $entry->lines()->create([
                'account_id' => $this->policy->tradePayablesAccount()->id,
                'description' => RecordText::of('Settling :supplier', ['supplier' => $locked->supplier?->code ?? RecordText::of('supplier')]),
                'debit' => DecimalMath::cost(DecimalMath::of($locked->amount)),
                'credit' => DecimalMath::cost('0'),
            ]);

            $entry->lines()->create([
                'account_id' => $account->id,
                'description' => $locked->reference,
                'debit' => DecimalMath::cost('0'),
                'credit' => DecimalMath::cost(DecimalMath::of($locked->amount)),
            ]);

            $posted = $this->post->handle($entry->refresh(), $userId);

            $locked->forceFill([
                'fiscal_period_id' => $period->id,
                'status' => SupplierPaymentStatus::Posted,
                'journal_entry_id' => $posted->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            // Each settled invoice's status now follows from what it still owes.
            foreach ($invoices as $invoice) {
                $this->refreshStatus->handle($invoice->refresh());
            }

            return $locked->refresh()->load(['allocations.invoice', 'journalEntry.lines']);
        });
    }

    private function assertPostable(SupplierPayment $payment): void
    {
        if (! $payment->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft supplier payment can be posted. :reference is :status.', [
                    'reference' => $payment->payment_number,
                    'status' => $payment->status->label(),
                ]),
            ]);
        }

        if ($payment->journal_entry_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Supplier payment :reference has already been posted.', [
                    'reference' => $payment->payment_number,
                ]),
            ]);
        }

        if (! $payment->supplier?->is_active) {
            throw ValidationException::withMessages([
                'supplier_id' => __('Payments cannot be made to an inactive supplier.'),
            ]);
        }

        if (! DecimalMath::isPositive(DecimalMath::of($payment->amount))) {
            throw ValidationException::withMessages([
                'amount' => __('The payment amount must be greater than zero.'),
            ]);
        }
    }

    private function resolvePeriod(SupplierPayment $payment): FiscalPeriod
    {
        $period = FiscalPeriod::openFor($payment->payment_date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'payment_date' => __('There is no open fiscal period covering :date, so this payment cannot be posted.', [
                    'date' => $payment->payment_date->toDateString(),
                ]),
            ]);
        }

        return $period;
    }

    private function resolvePaymentAccount(SupplierPayment $payment): Account
    {
        $account = Account::find($payment->payment_account_id);

        if ($account === null) {
            throw ValidationException::withMessages([
                'payment_account_id' => __('The selected payment account does not exist.'),
            ]);
        }

        $this->policy->assertPaymentAccount($account);

        return $account;
    }

    /**
     * Check every allocation, and return the invoices they settle.
     *
     * @param  Collection<int, SupplierPaymentAllocation>  $allocations
     * @return Collection<int, SupplierInvoice>
     */
    private function validateAllocations(SupplierPayment $payment, $allocations)
    {
        if ($allocations->isEmpty()) {
            throw ValidationException::withMessages([
                'allocations' => __('A payment must be allocated to at least one invoice. Payments on account are not supported.'),
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

            // Locked while its outstanding balance is read, so concurrent payments cannot
            // both settle the same amount.
            $invoice = SupplierInvoice::query()
                ->whereKey($allocation->supplier_invoice_id)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.supplier_invoice_id" => __('The selected invoice does not exist.'),
                ]);
            }

            if ($invoice->supplier_id !== $payment->supplier_id) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.supplier_invoice_id" => __('Invoice :reference belongs to a different supplier.', [
                        'reference' => $invoice->internal_invoice_number,
                    ]),
                ]);
            }

            if (! $invoice->status->isOutstanding()) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.supplier_invoice_id" => __('Invoice :reference is :status and cannot be paid.', [
                        'reference' => $invoice->internal_invoice_number,
                        'status' => $invoice->status->label(),
                    ]),
                ]);
            }

            $outstanding = $this->payables->outstanding($invoice);

            if (DecimalMath::compare($amount, $outstanding) > 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => __('Invoice :reference has only :outstanding outstanding; this allocation is :amount.', [
                        'reference' => $invoice->internal_invoice_number,
                        'outstanding' => $outstanding,
                        'amount' => $amount,
                    ]),
                ]);
            }

            $total = DecimalMath::add($total, $amount);
            $invoices->push($invoice);
        }

        $total = DecimalMath::cost($total);
        $amount = DecimalMath::cost(DecimalMath::of($payment->amount));

        if (DecimalMath::compare($total, $amount) !== 0) {
            throw ValidationException::withMessages([
                'allocations' => __('Allocations total :allocated but the payment is :amount. Every rupee must be allocated to an invoice.', [
                    'allocated' => $total,
                    'amount' => $amount,
                ]),
            ]);
        }

        return $invoices;
    }
}
