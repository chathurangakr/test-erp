<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\SalesCreditNoteKind;
use App\Enums\SalesCreditNoteStatus;
use App\Models\Customer;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a discount or quality allowance against one invoice of the customer, in one step:
 *
 *   Dr sales discounts and allowances (accounting_settings.sales_discount_account_id, 4190)
 *       Cr trade receivables
 *
 * The invoice's outstanding falls by the amount (AccountsReceivableService::creditedAmount) and
 * its status follows. Never more than the invoice still owes — money back for a discount is not
 * built — and never dated before the invoice. No goods move: goods coming back are a sales
 * return. Corrected by ReverseSalesCreditNote. See docs/ACCOUNTING_RULES.md §33.
 */
class RecordSalesCreditNote
{
    public function __construct(
        private readonly RecordOperationalJournal $accounting,
        private readonly AccountsReceivableService $receivables,
        private readonly RefreshSalesInvoiceStatus $refreshInvoice,
        private readonly GenerateReceivableReference $references,
    ) {}

    /**
     * @param  array{credit_date: string, sales_invoice_id: int, kind: string, amount: string, reason: string}  $data
     */
    public function handle(array $data, ?int $userId = null): SalesCreditNote
    {
        return DB::transaction(function () use ($data, $userId): SalesCreditNote {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($data['sales_invoice_id']);
            Customer::query()->whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::parse($data['credit_date'])->startOfDay();
            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));

            if (! $invoice->status->isOutstanding()) {
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

            if (DecimalMath::compare($amount, $outstanding, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => __('Invoice :reference is owed only :outstanding.', [
                        'reference' => $invoice->invoice_number,
                        'outstanding' => DecimalMath::money($outstanding),
                    ]),
                ]);
            }

            $note = SalesCreditNote::create([
                'credit_note_number' => $this->references->forCreditNote($date),
                'credit_date' => $date->toDateString(),
                'customer_id' => $invoice->customer_id,
                'sales_invoice_id' => $invoice->id,
                'kind' => SalesCreditNoteKind::from($data['kind']),
                'amount' => $amount,
                'reason' => $data['reason'],
                'status' => SalesCreditNoteStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->accounting->forSalesCreditNote($note->load(['customer', 'invoice']), $userId);
            $note->update(['journal_entry_id' => $journal->id]);
            $this->refreshInvoice->handle($invoice);

            return $note->refresh();
        });
    }
}
