<?php

namespace App\Actions\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft journal entry into the books.
 *
 * Everything happens inside ONE database transaction, so an unbalanced or partially written
 * journal can never be persisted — the balance rule is enforced before the status changes,
 * not afterwards by a report noticing.
 *
 * The rules, all of which must hold:
 *
 *   - the entry is a DRAFT and has not been posted before;
 *   - it has at least two lines;
 *   - every account exists, is active, and — for a manual entry — accepts manual posting;
 *   - every line is a debit OR a credit: never both, never neither, never negative;
 *   - total debits and total credits are both greater than zero;
 *   - total debits EQUAL total credits, exactly, at the stored scale;
 *   - the fiscal period is OPEN and its date range contains the journal date.
 *
 * All arithmetic runs through DecimalMath (BCMath). A float here would eventually produce a
 * journal whose two sides differ by a fraction of a cent, which is unrecoverable.
 *
 * See docs/ACCOUNTING_RULES.md.
 */
class PostJournalEntry
{
    public function handle(JournalEntry $entry, ?int $userId = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $userId): JournalEntry {
            // Re-read under lock so two concurrent posts cannot both pass the draft check.
            $locked = JournalEntry::query()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);
            $this->assertPeriodAccepts($locked);

            $lines = $locked->lines()->with('account')->orderBy('id')->get();

            $this->assertLinesAreValid($locked, $lines);
            $this->assertBalanced($lines);

            $locked->forceFill([
                'status' => JournalEntryStatus::Posted,
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            return $locked->refresh()->load(['lines.account', 'fiscalPeriod']);
        });
    }

    private function assertPostable(JournalEntry $entry): void
    {
        if (! $entry->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft journal entry can be posted. :reference is :status.', [
                    'reference' => $entry->journal_number,
                    'status' => $entry->status->label(),
                ]),
            ]);
        }

        if ($entry->posted_at !== null) {
            throw ValidationException::withMessages([
                'status' => __('Journal entry :reference has already been posted.', [
                    'reference' => $entry->journal_number,
                ]),
            ]);
        }
    }

    /**
     * The period gate.
     *
     * A closed period is the record of something already reported on, so nothing may be
     * added to it — including a reversal, which must find its own open period instead.
     */
    private function assertPeriodAccepts(JournalEntry $entry): void
    {
        $period = $entry->fiscalPeriod;

        if ($period === null) {
            throw ValidationException::withMessages([
                'fiscal_period_id' => __('A journal entry must belong to a fiscal period.'),
            ]);
        }

        if (! $period->status->acceptsPostings()) {
            throw ValidationException::withMessages([
                'fiscal_period_id' => __('Fiscal period :period is closed and cannot receive new entries.', [
                    'period' => $period->name,
                ]),
            ]);
        }

        if (! $period->contains($entry->journal_date)) {
            throw ValidationException::withMessages([
                'journal_date' => __('The journal date :date falls outside :period (:start to :end).', [
                    'date' => $entry->journal_date->toDateString(),
                    'period' => $period->name,
                    'start' => $period->start_date->toDateString(),
                    'end' => $period->end_date->toDateString(),
                ]),
            ]);
        }
    }

    /**
     * @param  Collection<int, JournalEntryLine>  $lines
     */
    private function assertLinesAreValid(JournalEntry $entry, Collection $lines): void
    {
        if ($lines->count() < 2) {
            throw ValidationException::withMessages([
                'lines' => __('A journal entry must have at least two lines: something debited and something credited.'),
            ]);
        }

        foreach ($lines as $index => $line) {
            $field = "lines.{$index}";
            $account = $line->account;

            if ($account === null) {
                throw ValidationException::withMessages([
                    $field.'.account_id' => __('The selected account does not exist.'),
                ]);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    $field.'.account_id' => __('Account :account is inactive and cannot be posted to.', [
                        'account' => $account->code,
                    ]),
                ]);
            }

            // A system-generated entry names its source document; a manual one does not, and
            // only a manual one is restricted to hand-postable accounts.
            if ($entry->source_type === null && ! $account->acceptsManualPosting()) {
                throw ValidationException::withMessages([
                    $field.'.account_id' => $account->is_control_account
                        ? __('Account :account is a control account and is posted to by the system, not by hand.', [
                            'account' => $account->code,
                        ])
                        : __('Account :account does not accept manual posting.', ['account' => $account->code]),
                ]);
            }

            $debit = DecimalMath::of($line->debit);
            $credit = DecimalMath::of($line->credit);

            if (DecimalMath::isNegative($debit) || DecimalMath::isNegative($credit)) {
                throw ValidationException::withMessages([
                    $field.'.debit' => __('A journal line amount cannot be negative.'),
                ]);
            }

            $hasDebit = DecimalMath::isPositive($debit);
            $hasCredit = DecimalMath::isPositive($credit);

            if ($hasDebit && $hasCredit) {
                throw ValidationException::withMessages([
                    $field.'.debit' => __('A journal line is either a debit or a credit, never both.'),
                ]);
            }

            if (! $hasDebit && ! $hasCredit) {
                throw ValidationException::withMessages([
                    $field.'.debit' => __('A journal line must carry an amount.'),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, JournalEntryLine>  $lines
     */
    private function assertBalanced(Collection $lines): void
    {
        $debits = DecimalMath::cost($lines->reduce(
            fn (string $carry, JournalEntryLine $line): string => DecimalMath::add($carry, DecimalMath::of($line->debit)),
            DecimalMath::zero(),
        ));

        $credits = DecimalMath::cost($lines->reduce(
            fn (string $carry, JournalEntryLine $line): string => DecimalMath::add($carry, DecimalMath::of($line->credit)),
            DecimalMath::zero(),
        ));

        if (! DecimalMath::isPositive($debits) || ! DecimalMath::isPositive($credits)) {
            throw ValidationException::withMessages([
                'lines' => __('A journal entry must debit and credit something.'),
            ]);
        }

        if (DecimalMath::compare($debits, $credits) !== 0) {
            throw ValidationException::withMessages([
                'lines' => __('The journal does not balance: debits :debits, credits :credits, difference :difference.', [
                    'debits' => $debits,
                    'credits' => $credits,
                    'difference' => DecimalMath::cost(DecimalMath::sub($debits, $credits)),
                ]),
            ]);
        }
    }
}
