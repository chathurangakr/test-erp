<?php

namespace App\Services\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\CashBankAccount;
use App\Models\JournalEntryLine;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which of a bank account's journal lines a bank statement has shown — read-only. A line is
 * reconciled once a standing (COMPLETED) reconciliation ticked it; the reconciled balance is the
 * net of those lines, and must equal the latest statement balance. Posted and reversed journals
 * both count, as everywhere (a reversal is its own line). See docs/ACCOUNTING_RULES.md §36.
 */
class BankReconciliationService
{
    /** @return Collection<int, int> the journal line ids standing reconciliations ticked */
    public function reconciledLineIds(CashBankAccount $account): Collection
    {
        return BankReconciliationLine::query()
            ->whereHas('reconciliation', fn (Builder $query) => $query
                ->where('cash_bank_account_id', $account->id)
                ->where('status', BankReconciliation::COMPLETED))
            ->pluck('journal_entry_line_id');
    }

    /** The net (in − out) of every reconciled line: what the last statement said was in the bank. */
    public function reconciledBalance(CashBankAccount $account): string
    {
        return DecimalMath::cost($this->lines($account)
            ->whereIn('id', $this->reconciledLineIds($account))
            ->get()
            ->reduce(fn (string $sum, JournalEntryLine $line): string => DecimalMath::add($sum, DecimalMath::sub((string) $line->debit, (string) $line->credit)), DecimalMath::zero()));
    }

    /**
     * The account's lines no statement has shown yet, dated up to $upTo, oldest first.
     *
     * @return Collection<int, JournalEntryLine>
     */
    public function unreconciledLines(CashBankAccount $account, ?CarbonInterface $upTo = null): Collection
    {
        return $this->lines($account)
            ->whereNotIn('id', $this->reconciledLineIds($account))
            ->when($upTo, fn (Builder $query, CarbonInterface $date) => $query->whereHas('entry', fn (Builder $entry) => $entry->whereOnOrBefore('journal_date', $date->toDateString())))
            ->with('entry:id,journal_number,journal_date,description,source_type,source_id')
            ->get()
            ->sortBy(fn (JournalEntryLine $line): string => $line->entry->journal_date->toDateString().'-'.str_pad((string) $line->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /** The latest standing reconciliation of the account, if any. */
    public function latest(CashBankAccount $account): ?BankReconciliation
    {
        return BankReconciliation::query()
            ->where('cash_bank_account_id', $account->id)
            ->where('status', BankReconciliation::COMPLETED)
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Whether a statement has shown this journal line. */
    public function isReconciled(int $journalLineId): bool
    {
        return BankReconciliationLine::query()
            ->where('journal_entry_line_id', $journalLineId)
            ->whereHas('reconciliation', fn (Builder $query) => $query->where('status', BankReconciliation::COMPLETED))
            ->exists();
    }

    /** @return Builder<JournalEntryLine> */
    private function lines(CashBankAccount $account): Builder
    {
        return JournalEntryLine::query()
            ->where('account_id', $account->account_id)
            ->whereHas('entry', fn (Builder $entry) => $entry->whereIn('status', [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value]));
    }
}
