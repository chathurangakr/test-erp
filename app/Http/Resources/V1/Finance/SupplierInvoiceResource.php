<?php

namespace App\Http\Resources\V1\Finance;

use App\Http\Resources\V1\ApiResource;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierPaymentAllocation;
use App\Services\Accounting\AccountsPayableService;
use App\Support\BusinessClock;
use Illuminate\Http\Request;

/**
 * A supplier invoice. Total, paid and outstanding come from AccountsPayableService — the same
 * figures the AP aging and the Trade Payables invariant use — never re-derived here.
 *
 * @mixin SupplierInvoice
 */
class SupplierInvoiceResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payables = app(AccountsPayableService::class);

        return [
            'id' => $this->id,
            'internal_invoice_number' => $this->internal_invoice_number,
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'status' => $this->enum($this->status),
            'supplier' => $this->ref($this->whenLoaded('supplier', fn () => $this->supplier, null)),
            'invoice_date' => $this->date($this->invoice_date),
            'due_date' => $this->date($this->due_date),
            'description' => $this->description,
            'total' => $payables->invoiceTotal($this->resource),
            'paid' => $payables->paidAmount($this->resource),
            'outstanding' => $payables->outstanding($this->resource),
            'is_overdue' => $this->status->isOutstanding()
                && BusinessClock::isPastDue($this->due_date),
            'actions' => [
                'can_edit' => $this->status->isEditable(),
                'can_post' => $this->status->canPost(),
                'can_reverse' => $this->status->canReverse() && $this->reversal_journal_entry_id === null,
                'can_cancel' => $this->status->canCancel(),
            ],
            'journal_number' => $this->whenLoaded('journalEntry', fn () => $this->journalEntry?->journal_number),
            'reversal_journal_number' => $this->whenLoaded('reversalJournalEntry', fn () => $this->reversalJournalEntry?->journal_number),
            'created_by' => $this->userRef($this->whenLoaded('createdBy', fn () => $this->createdBy, null)),
            'posted_by' => $this->userRef($this->whenLoaded('postedBy', fn () => $this->postedBy, null)),
            'posted_at' => $this->timestamp($this->posted_at),
            'reversed_by' => $this->userRef($this->whenLoaded('reversedBy', fn () => $this->reversedBy, null)),
            'reversed_at' => $this->timestamp($this->reversed_at),
            'created_at' => $this->timestamp($this->created_at),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (SupplierInvoiceLine $line): array => [
                'id' => $line->id,
                'line_type' => $this->enum($line->line_type),
                'goods_receipt' => $line->relationLoaded('goodsReceipt') && $line->goodsReceipt !== null
                    ? ['id' => $line->goodsReceipt->id, 'grn_number' => $line->goodsReceipt->grn_number]
                    : null,
                'debit_account' => $this->ref($line->relationLoaded('debitAccount') ? $line->debitAccount : null),
                'description' => $line->description,
                'amount' => $this->money($line->amount),
            ])->values()),
            'payments' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn (SupplierPaymentAllocation $allocation): array => [
                'payment_id' => $allocation->payment?->id,
                'payment_number' => $allocation->payment?->payment_number,
                'payment_date' => $this->date($allocation->payment?->payment_date),
                'status' => $this->enum($allocation->payment?->status),
                'amount' => $this->money($allocation->amount),
            ])->values()),
        ];
    }
}
