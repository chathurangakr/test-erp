<?php

namespace App\Http\Resources\V1\Finance;

use App\Http\Resources\V1\ApiResource;
use App\Models\ExpenseVoucher;
use App\Models\ExpenseVoucherLine;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;

/**
 * An expense voucher — an expense paid immediately from cash or the bank. It raises no
 * payable; a named supplier is for reporting only.
 *
 * @mixin ExpenseVoucher
 */
class ExpenseVoucherResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'voucher_number' => $this->voucher_number,
            'status' => $this->enum($this->status),
            'voucher_date' => $this->date($this->voucher_date),
            'payment_account' => $this->whenLoaded('cashBankAccount', fn () => $this->cashBankAccount === null ? null : [
                'id' => $this->cashBankAccount->id,
                'name' => $this->cashBankAccount->display_name,
                'code' => $this->cashBankAccount->relationLoaded('account') ? $this->cashBankAccount->account?->code : null,
            ], null),
            'supplier' => $this->ref($this->whenLoaded('supplier', fn () => $this->supplier, null)),
            'payee' => $this->payee(),
            'payee_name' => $this->payee_name,
            'reference' => $this->reference,
            'description' => $this->description,
            'total' => $this->whenLoaded('lines', fn () => $this->money($this->total())),
            'actions' => [
                'can_edit' => $this->status->isEditable(),
                'can_post' => $this->status->canPost(),
                'can_reverse' => $this->status->canReverse() && $this->reversal_journal_entry_id === null,
                'can_cancel' => $this->status->canCancel(),
            ],
            'journal' => $this->whenLoaded('journalEntry', fn () => $this->journalEntry === null ? null : [
                'id' => $this->journalEntry->id,
                'journal_number' => $this->journalEntry->journal_number,
                'lines' => $this->journalEntry->lines->map(fn (JournalEntryLine $line): array => [
                    'account' => $this->ref($line->account),
                    'debit' => $this->money($line->debit),
                    'credit' => $this->money($line->credit),
                ])->values(),
            ]),
            'reversal_journal_number' => $this->whenLoaded('reversalJournalEntry', fn () => $this->reversalJournalEntry?->journal_number),
            'created_by' => $this->userRef($this->whenLoaded('createdBy', fn () => $this->createdBy, null)),
            'posted_by' => $this->userRef($this->whenLoaded('postedBy', fn () => $this->postedBy, null)),
            'posted_at' => $this->timestamp($this->posted_at),
            'reversed_by' => $this->userRef($this->whenLoaded('reversedBy', fn () => $this->reversedBy, null)),
            'reversed_at' => $this->timestamp($this->reversed_at),
            'created_at' => $this->timestamp($this->created_at),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (ExpenseVoucherLine $line): array => [
                'id' => $line->id,
                'expense_account' => $this->ref($line->relationLoaded('expenseAccount') ? $line->expenseAccount : null),
                'description' => $line->description,
                'amount' => $this->money($line->amount),
            ])->values()),
        ];
    }
}
