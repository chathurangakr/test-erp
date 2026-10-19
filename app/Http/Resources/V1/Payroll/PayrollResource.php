<?php

namespace App\Http\Resources\V1\Payroll;

use App\Http\Resources\V1\ApiResource;
use App\Models\JournalEntryLine;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Http\Request;

/**
 * One month's salaries, paid in one step (`Dr Wages / Cr Employee Advances / Cr cash or
 * bank`). Each line keeps what one employee was paid; corrected only by reversal.
 *
 * @mixin PayrollRun
 */
class PayrollResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_number' => $this->payroll_number,
            'status' => $this->enum($this->status),
            'month' => $this->period_start->format('Y-m'),
            'period_start' => $this->date($this->period_start),
            'period_end' => $this->date($this->period_end),
            'pay_date' => $this->date($this->pay_date),
            'employees' => $this->whenLoaded('lines', fn () => $this->lines->count()),
            'gross_pay' => $this->whenLoaded('lines', fn () => $this->money($this->total('gross_pay'))),
            'advances_deducted' => $this->whenLoaded('lines', fn () => $this->money($this->total('advances_deducted'))),
            'net_pay' => $this->whenLoaded('lines', fn () => $this->money($this->total('net_pay'))),
            'payment_account' => $this->whenLoaded('cashBankAccount', fn () => $this->cashBankAccount === null ? null : [
                'id' => $this->cashBankAccount->id,
                'name' => $this->cashBankAccount->display_name,
            ], null),
            'payment_method' => $this->enum($this->payment_method),
            'reference' => $this->reference,
            'actions' => [
                'can_reverse' => $this->status->canReverse(),
            ],
            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->sortBy(fn (PayrollLine $line): string => (string) $line->employee?->name)
                ->map(fn (PayrollLine $line): array => [
                    'employee' => $this->ref($line->relationLoaded('employee') ? $line->employee : null),
                    'full_days' => $line->full_days,
                    'half_days' => $line->half_days,
                    'leave_days' => $line->leave_days,
                    'gross_pay' => $this->money($line->gross_pay),
                    'advances_deducted' => $this->money($line->advances_deducted),
                    'net_pay' => $this->money($line->net_pay),
                ])->values()),
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
