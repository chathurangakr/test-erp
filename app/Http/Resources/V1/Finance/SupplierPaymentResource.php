<?php

namespace App\Http\Resources\V1\Finance;

use App\Http\Resources\V1\ApiResource;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Http\Request;

/**
 * A supplier payment, always fully allocated to invoices.
 *
 * @mixin SupplierPayment
 */
class SupplierPaymentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'status' => $this->enum($this->status),
            'supplier' => $this->ref($this->whenLoaded('supplier', fn () => $this->supplier, null)),
            'payment_date' => $this->date($this->payment_date),
            'payment_account' => $this->ref($this->whenLoaded('paymentAccount', fn () => $this->paymentAccount, null)),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'amount' => $this->money($this->amount),
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
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn (SupplierPaymentAllocation $allocation): array => [
                'supplier_invoice_id' => $allocation->supplier_invoice_id,
                'internal_invoice_number' => $allocation->relationLoaded('invoice') ? $allocation->invoice?->internal_invoice_number : null,
                'supplier_invoice_number' => $allocation->relationLoaded('invoice') ? $allocation->invoice?->supplier_invoice_number : null,
                'amount' => $this->money($allocation->amount),
            ])->values()),
        ];
    }
}
