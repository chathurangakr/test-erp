<?php

namespace App\Http\Resources\V1\Finance;

use App\Http\Resources\V1\ApiResource;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Services\Accounting\AccountsReceivableService;
use App\Support\BusinessClock;
use Illuminate\Http\Request;

/**
 * A sales invoice. Every line bills a delivery line; `delivered_value` is that line's stored
 * sales_value — never recalculated from quantity × price. Total, received and outstanding come
 * from AccountsReceivableService, the same figures the AR aging and Trade Receivables
 * invariant use.
 *
 * @mixin SalesInvoice
 */
class SalesInvoiceResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $receivables = app(AccountsReceivableService::class);

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->enum($this->status),
            'customer' => $this->ref($this->whenLoaded('customer', fn () => $this->customer, null)),
            'invoice_date' => $this->date($this->invoice_date),
            'due_date' => $this->date($this->due_date),
            'customer_reference' => $this->customer_reference,
            'description' => $this->description,
            'total' => $receivables->invoiceTotal($this->resource),
            'received' => $receivables->receivedAmount($this->resource),
            'outstanding' => $receivables->outstanding($this->resource),
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
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (SalesInvoiceLine $line): array => [
                'id' => $line->id,
                'delivery_line_id' => $line->delivery_line_id,
                'delivery' => $line->relationLoaded('deliveryLine') && $line->deliveryLine?->delivery !== null
                    ? ['id' => $line->deliveryLine->delivery->id, 'delivery_number' => $line->deliveryLine->delivery->delivery_number]
                    : null,
                'product' => $this->ref($line->relationLoaded('product') ? $line->product : null),
                'delivered_value' => $line->relationLoaded('deliveryLine') ? $this->money($line->deliveryLine?->sales_value) : null,
                'description' => $line->description,
                'amount' => $this->money($line->amount),
            ])->values()),
            'receipts' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn (CustomerReceiptAllocation $allocation): array => [
                'receipt_id' => $allocation->receipt?->id,
                'receipt_number' => $allocation->receipt?->receipt_number,
                'receipt_date' => $this->date($allocation->receipt?->receipt_date),
                'status' => $this->enum($allocation->receipt?->status),
                'amount' => $this->money($allocation->amount),
            ])->values()),
        ];
    }
}
