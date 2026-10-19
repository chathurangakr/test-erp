<?php

namespace App\Http\Resources\V1\Payroll;

use App\Http\Resources\V1\ApiResource;
use App\Models\JournalEntryLine;
use App\Models\SalaryAdvance;
use Illuminate\Http\Request;

/**
 * A salary advance — paid and journalled in one step (`Dr Employee Advances / Cr cash or
 * bank`); corrected only by reversal.
 *
 * @mixin SalaryAdvance
 */
class SalaryAdvanceResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'advance_number' => $this->advance_number,
            'status' => $this->enum($this->status),
            'advance_date' => $this->date($this->advance_date),
            'employee' => $this->ref($this->whenLoaded('employee', fn () => $this->employee, null)),
            'amount' => $this->money($this->amount),
            'payment_account' => $this->whenLoaded('cashBankAccount', fn () => $this->cashBankAccount === null ? null : [
                'id' => $this->cashBankAccount->id,
                'name' => $this->cashBankAccount->display_name,
            ], null),
            'payment_method' => $this->enum($this->payment_method),
            'reference' => $this->reference,
            'note' => $this->note,
            'actions' => [
                'can_reverse' => $this->status->canReverse(),
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
            'posted_at' => $this->timestamp($this->posted_at),
            'reversed_by' => $this->userRef($this->whenLoaded('reversedBy', fn () => $this->reversedBy, null)),
            'reversed_at' => $this->timestamp($this->reversed_at),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
