<?php

namespace App\Http\Resources\V1\Finance;

use App\Http\Resources\V1\ApiResource;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use Illuminate\Http\Request;

/**
 * A customer receipt, always fully allocated to invoices.
 *
 * @mixin CustomerReceipt
 */
class CustomerReceiptResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'status' => $this->enum($this->status),
            'customer' => $this->ref($this->whenLoaded('customer', fn () => $this->customer, null)),
            'receipt_date' => $this->date($this->receipt_date),
            'receipt_account' => $this->ref($this->whenLoaded('receiptAccount', fn () => $this->receiptAccount, null)),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'amount' => $this->money($this->amount),
            'payment_method' => $this->payment_method === null ? null : $this->enum($this->payment_method),
            'cheque' => $this->cheque_number === null ? null : [
                'number' => $this->cheque_number,
                'date' => $this->date($this->cheque_date),
                'bank' => $this->cheque_bank,
            ],
            'allocated' => $this->whenLoaded('allocations', fn () => $this->allocatedTotal()),
            'actions' => [
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
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn (CustomerReceiptAllocation $allocation): array => [
                'sales_invoice_id' => $allocation->sales_invoice_id,
                'invoice_number' => $allocation->relationLoaded('invoice') ? $allocation->invoice?->invoice_number : null,
                'amount' => $this->money($allocation->amount),
            ])->values()),
        ];
    }
}
