<?php

namespace App\Http\Resources\V1\Finance;

use App\Http\Resources\V1\ApiResource;
use App\Models\CashBankAccount;
use App\Models\FundTransfer;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;

/**
 * A transfer between the factory's own cash and bank accounts — a balance-sheet event and
 * nothing else. Combined cash + bank is the same before and after.
 *
 * @mixin FundTransfer
 */
class FundTransferResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'status' => $this->enum($this->status),
            'transfer_date' => $this->date($this->transfer_date),
            'from_account' => $this->whenLoaded('fromAccount', fn () => $this->account($this->fromAccount), null),
            'to_account' => $this->whenLoaded('toAccount', fn () => $this->account($this->toAccount), null),
            'amount' => $this->money($this->amount),
            'reference' => $this->reference,
            'description' => $this->description,
            'actions' => [
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
        ];
    }

    /**
     * @return array{id: int, name: string, code: string|null}|null
     */
    private function account(?CashBankAccount $configuration): ?array
    {
        return $configuration === null ? null : [
            'id' => $configuration->id,
            'name' => $configuration->display_name,
            'code' => $configuration->relationLoaded('account') ? $configuration->account?->code : null,
        ];
    }
}
