<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Support\DecimalMath;
use App\Support\SourceDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Account balances, derived from posted journal lines.
 *
 * **Nothing here is stored.** Every figure is computed from `journal_entry_lines` on
 * request, exactly as stock is computed from the inventory ledger. A stored balance has no
 * history: when it drifts there is no way to discover when or why, and no way to rebuild it.
 *
 * Only entries that reached the books are counted — POSTED, and REVERSED (whose own lines
 * remain posted and are cancelled out by the reversing entry's lines). A DRAFT or CANCELLED
 * entry contributes nothing.
 *
 * See docs/ACCOUNTING_RULES.md §3.
 */
class AccountingBalanceService
{
    /**
     * Total debits posted to an account.
     */
    public function debitTotal(int $accountId, ?Carbon $asOf = null): string
    {
        return $this->sum('debit', fn (Builder $query) => $query->where('account_id', $accountId), $asOf);
    }

    /**
     * Total credits posted to an account.
     */
    public function creditTotal(int $accountId, ?Carbon $asOf = null): string
    {
        return $this->sum('credit', fn (Builder $query) => $query->where('account_id', $accountId), $asOf);
    }

    /**
     * Debits minus credits — the raw arithmetic balance, which may be negative.
     */
    public function netBalance(int $accountId, ?Carbon $asOf = null): string
    {
        return DecimalMath::cost(DecimalMath::sub(
            $this->debitTotal($accountId, $asOf),
            $this->creditTotal($accountId, $asOf),
        ));
    }

    /**
     * The balance as the account is normally read.
     *
     * A debit-normal account with debits exceeding credits reports POSITIVE, and so does a
     * credit-normal account with credits exceeding debits. This is presentation only —
     * nothing about how a line was posted changes.
     */
    public function balance(Account $account, ?Carbon $asOf = null): string
    {
        $net = $this->netBalance($account->id, $asOf);

        return $account->normal_balance->isDebit()
            ? $net
            : DecimalMath::cost(DecimalMath::mul($net, '-1'));
    }

    /**
     * A net balance as a financial statement should print it.
     *
     * The ledger stores one thing — debits minus credits — and that never changes. A
     * statement, though, is read by people: revenue of 260,000 belongs on the page as
     * 260,000, not as the -260,000 its credit balance arithmetically is. This flips the sign
     * for the credit-natured classes and leaves the debit-natured ones alone.
     *
     * The sign comes from the account **type**, not from each account's stored
     * `normal_balance`, so one oddly configured account cannot make a section total
     * incoherent. An account carrying a genuinely abnormal balance — a revenue account in
     * debit — still prints negative, which is exactly the signal a reader needs.
     *
     * Presentation only. No caller of this may feed the result back into the ledger.
     */
    public function presentationAmount(AccountType $type, string $netBalance): string
    {
        return $type->defaultNormalBalance()->isDebit()
            ? DecimalMath::cost($netBalance)
            : DecimalMath::cost(DecimalMath::mul($netBalance, '-1'));
    }

    /**
     * Debits, credits and net for one account within one fiscal period.
     *
     * @return array{debit: string, credit: string, net: string}
     */
    public function balanceForPeriod(int $accountId, FiscalPeriod $period): array
    {
        $scope = fn (Builder $query) => $query
            ->where('account_id', $accountId)
            ->whereHas('entry', fn (Builder $entry) => $entry->where('fiscal_period_id', $period->id));

        $debit = $this->sum('debit', $scope);
        $credit = $this->sum('credit', $scope);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'net' => DecimalMath::cost(DecimalMath::sub($debit, $credit)),
        ];
    }

    /**
     * The combined net balance of every account of one type.
     */
    public function balanceForType(AccountType $type, ?Carbon $asOf = null): string
    {
        $scope = fn (Builder $query) => $query
            ->whereHas('account', fn (Builder $account) => $account->where('account_type', $type));

        return DecimalMath::cost(DecimalMath::sub(
            $this->sum('debit', $scope, $asOf),
            $this->sum('credit', $scope, $asOf),
        ));
    }

    /**
     * Every account's debit, credit and net totals, keyed by account id.
     *
     * One grouped query rather than one per account, because a trial balance over a full
     * chart would otherwise be dozens of round trips.
     *
     * `$from` narrows the window at the near end, which a profit and loss statement needs
     * and a balance sheet never does: a balance is everything up to a date, but performance
     * is what happened between two.
     *
     * @return Collection<int, array{debit: string, credit: string, net: string}>
     */
    public function totalsByAccount(?Carbon $asOf = null, ?FiscalPeriod $period = null, ?Carbon $from = null): Collection
    {
        $rows = $this->postedLines($asOf, $period, $from)
            ->selectRaw('account_id, SUM(debit) as debit_total, SUM(credit) as credit_total')
            ->groupBy('account_id')
            ->get();

        return $rows->mapWithKeys(function (object $row): array {
            $debit = DecimalMath::cost(DecimalMath::of((string) ($row->debit_total ?? '0')));
            $credit = DecimalMath::cost(DecimalMath::of((string) ($row->credit_total ?? '0')));

            return [
                (int) $row->account_id => [
                    'debit' => $debit,
                    'credit' => $credit,
                    'net' => DecimalMath::cost(DecimalMath::sub($debit, $credit)),
                ],
            ];
        });
    }

    /**
     * The trial balance.
     *
     * Each account's net is shown on the side it falls: a positive net in the debit column,
     * a negative net in the credit column. Because every posted entry balances, the two
     * column totals must be identical — that identity is the report's whole purpose, and a
     * difference means something is wrong that no amount of presentation can hide.
     *
     * @return array{
     *     rows: list<array{account_id: int, code: string, name: string, type: string, type_label: string, debit: string, credit: string}>,
     *     debit_total: string,
     *     credit_total: string,
     *     balanced: bool
     * }
     */
    public function trialBalance(?Carbon $asOf = null, ?FiscalPeriod $period = null): array
    {
        $totals = $this->totalsByAccount($asOf, $period);

        $accounts = Account::query()
            ->whereIn('id', $totals->keys()->all())
            ->orderBy('code')
            ->get();

        $rows = [];
        $debitTotal = DecimalMath::zero();
        $creditTotal = DecimalMath::zero();

        foreach ($accounts as $account) {
            $net = $totals[$account->id]['net'];

            if (DecimalMath::isZero($net)) {
                // An account whose postings cancel out has no balance to show.
                continue;
            }

            $isDebit = DecimalMath::isPositive($net);
            $amount = $isDebit ? $net : DecimalMath::cost(DecimalMath::mul($net, '-1'));

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->account_type->value,
                'type_label' => $account->account_type->label(),
                'debit' => $isDebit ? $amount : DecimalMath::cost('0'),
                'credit' => $isDebit ? DecimalMath::cost('0') : $amount,
            ];

            $isDebit
                ? $debitTotal = DecimalMath::add($debitTotal, $amount)
                : $creditTotal = DecimalMath::add($creditTotal, $amount);
        }

        $debitTotal = DecimalMath::cost($debitTotal);
        $creditTotal = DecimalMath::cost($creditTotal);

        return [
            'rows' => $rows,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'balanced' => DecimalMath::compare($debitTotal, $creditTotal) === 0,
        ];
    }

    /**
     * The general ledger for one account: every posted line, oldest first, with a running
     * balance.
     *
     * The running balance is calculated here and **never persisted**. It is a presentation
     * of the lines, not a fact about the account.
     *
     * @return array{
     *     opening_balance: string,
     *     rows: list<array<string, mixed>>,
     *     debit_total: string,
     *     credit_total: string,
     *     closing_balance: string
     * }
     */
    public function generalLedger(Account $account, ?Carbon $from = null, ?Carbon $to = null): array
    {
        // Everything before the window becomes the opening balance, so the running balance
        // is true rather than starting from an arbitrary zero.
        $opening = $from === null
            ? DecimalMath::cost('0')
            : $this->netBalance($account->id, $from->copy()->subDay());

        $lines = $this->postedLines($to)
            ->where('account_id', $account->id)
            ->when($from, fn (Builder $query, Carbon $date) => $query->whereHas(
                'entry',
                fn (Builder $entry) => $entry->whereDate('journal_date', '>=', $date->toDateString()),
            ))
            ->with(['entry:id,journal_number,journal_date,description,status,source_type,source_id'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.journal_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.id')
            ->select('journal_entry_lines.*')
            ->get();

        $running = $opening;
        $debitTotal = DecimalMath::zero();
        $creditTotal = DecimalMath::zero();
        $rows = [];

        foreach ($lines as $line) {
            $running = DecimalMath::cost(DecimalMath::add($running, $line->signedAmount()));
            $debitTotal = DecimalMath::add($debitTotal, DecimalMath::of($line->debit));
            $creditTotal = DecimalMath::add($creditTotal, DecimalMath::of($line->credit));

            $rows[] = [
                'id' => $line->id,
                'journal_entry_id' => $line->journal_entry_id,
                'journal_number' => $line->entry?->journal_number,
                'journal_date' => $line->entry?->journal_date?->toDateString(),
                'description' => $line->description ?? $line->entry?->description,
                'source' => SourceDocument::label($line->entry?->source_type),
                'debit' => $line->debit,
                'credit' => $line->credit,
                // Presentation only. Never stored.
                'running_balance' => $running,
            ];
        }

        return [
            'opening_balance' => $opening,
            'rows' => $rows,
            'debit_total' => DecimalMath::cost($debitTotal),
            'credit_total' => DecimalMath::cost($creditTotal),
            'closing_balance' => $running,
        ];
    }

    /**
     * Lines belonging to entries that actually reached the books.
     *
     * @return Builder<JournalEntryLine>
     */
    private function postedLines(?Carbon $asOf = null, ?FiscalPeriod $period = null, ?Carbon $from = null): Builder
    {
        return JournalEntryLine::query()
            ->whereHas('entry', function (Builder $entry) use ($asOf, $period, $from): void {
                $entry->whereIn('status', [
                    JournalEntryStatus::Posted->value,
                    JournalEntryStatus::Reversed->value,
                ]);

                if ($asOf !== null) {
                    $entry->whereDate('journal_date', '<=', $asOf->toDateString());
                }

                if ($from !== null) {
                    $entry->whereDate('journal_date', '>=', $from->toDateString());
                }

                if ($period !== null) {
                    $entry->where('fiscal_period_id', $period->id);
                }
            });
    }

    /**
     * @param  callable(Builder<JournalEntryLine>): mixed  $scope
     */
    private function sum(string $column, callable $scope, ?Carbon $asOf = null): string
    {
        $query = $this->postedLines($asOf);
        $scope($query);

        return DecimalMath::cost(DecimalMath::of((string) ($query->sum($column) ?? '0')));
    }
}
