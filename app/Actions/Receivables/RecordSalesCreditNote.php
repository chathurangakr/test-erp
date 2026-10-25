<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\PaymentMethod;
use App\Enums\SalesCreditNoteKind;
use App\Enums\SalesCreditNoteSettlement;
use App\Enums\SalesCreditNoteStatus;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\DecimalMath;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a discount or quality allowance against one invoice of the customer, in one step:
 *
 *   CREDIT: Dr sales discounts and allowances (accounting_settings.sales_discount_account_id, 4190)
 *               Cr trade receivables
 *   REFUND: Dr sales discounts and allowances / Cr cash or bank
 *
 * A CREDIT lowers the invoice's outstanding (AccountsReceivableService::creditedAmount) and its
 * status follows; never more than the invoice still owes. A REFUND pays money back for an invoice
 * already paid and leaves what it is owed alone; never more than the customer paid on it, less
 * what was already given back (AccountsReceivableService::refundableAmount). Never dated before
 * the invoice. No goods move: goods coming back are a sales return. Corrected by
 * ReverseSalesCreditNote. See docs/ACCOUNTING_RULES.md §33.
 */
class RecordSalesCreditNote
{
    public function __construct(
        private readonly RecordOperationalJournal $accounting,
        private readonly AccountsReceivableService $receivables,
        private readonly RefreshSalesInvoiceStatus $refreshInvoice,
        private readonly GenerateReceivableReference $references,
        private readonly AccountingPolicy $policy,
    ) {}

    /**
     * @param  array{credit_date: string, sales_invoice_id: int, kind: string, settlement?: string, cash_bank_account_id?: int|null, payment_method?: string|null, amount: string, reason: string}  $data
     */
    public function handle(array $data, ?int $userId = null): SalesCreditNote
    {
        return DB::transaction(function () use ($data, $userId): SalesCreditNote {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($data['sales_invoice_id']);
            Customer::query()->whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::parse($data['credit_date'])->startOfDay();
            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));
            $settlement = SalesCreditNoteSettlement::from($data['settlement'] ?? SalesCreditNoteSettlement::Credit->value);
            $isRefund = $settlement === SalesCreditNoteSettlement::Refund;

            if ($isRefund && ! $invoice->status->affectsReceivables()) {
                throw ValidationException::withMessages([
                    'sales_invoice_id' => __('Invoice :reference is :status.', ['reference' => $invoice->invoice_number, 'status' => $invoice->status->label()]),
                ]);
            }

            if (! $isRefund && ! $invoice->status->isOutstanding()) {
                throw ValidationException::withMessages([
                    'sales_invoice_id' => __('Invoice :reference is not owed anything (it is :status).', ['reference' => $invoice->invoice_number, 'status' => $invoice->status->label()]),
                ]);
            }

            if (CarbonImmutable::parse($invoice->invoice_date)->greaterThan($date)) {
                throw ValidationException::withMessages([
                    'credit_date' => __('Invoice :reference is dated after this credit note.', ['reference' => $invoice->invoice_number]),
                ]);
            }

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
            }

            $outstanding = $this->receivables->outstanding($invoice);
            $refundable = $this->receivables->refundableAmount($invoice);

            if ($isRefund && DecimalMath::compare($amount, $refundable, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => __('The customer has paid only :paid on invoice :reference, so no more than that can be given back. Reduce what they owe instead.', [
                        'paid' => DisplayNumber::money($refundable),
                        'reference' => $invoice->invoice_number,
                    ]),
                ]);
            }

            $configuration = null;
            $method = null;

            if ($isRefund) {
                $method = PaymentMethod::from((string) ($data['payment_method'] ?? ''));
                $configuration = CashBankAccount::query()->with('account')->find($data['cash_bank_account_id'] ?? 0)
                    ?? throw ValidationException::withMessages(['cash_bank_account_id' => __('Choose the cash or bank account the money is paid from.')]);
                $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('money back'));
                $this->policy->assertMethodFitsAccount($method, $configuration, 'cash_bank_account_id');
            }

            if (! $isRefund && DecimalMath::compare($amount, $outstanding, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => __('Invoice :reference is owed only :outstanding.', [
                        'reference' => $invoice->invoice_number,
                        'outstanding' => DisplayNumber::money($outstanding),
                    ]),
                ]);
            }

            $note = SalesCreditNote::create([
                'credit_note_number' => $this->references->forCreditNote($date),
                'credit_date' => $date->toDateString(),
                'customer_id' => $invoice->customer_id,
                'sales_invoice_id' => $invoice->id,
                'kind' => SalesCreditNoteKind::from($data['kind']),
                'settlement' => $settlement,
                'cash_bank_account_id' => $configuration?->id,
                'payment_method' => $method,
                'amount' => $amount,
                'reason' => $data['reason'],
                'status' => SalesCreditNoteStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->accounting->forSalesCreditNote($note->load(['customer', 'invoice', 'cashBankAccount']), $userId);
            $note->update(['journal_entry_id' => $journal->id]);
            $this->refreshInvoice->handle($invoice);

            return $note->refresh();
        });
    }
}
