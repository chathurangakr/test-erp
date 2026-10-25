<?php

namespace App\Services\Accounting;

use App\Enums\CashBankAccountKind;
use App\Enums\JournalEntryStatus;
use App\Models\CashBankAccount;
use App\Models\CustomerReceipt;
use App\Models\ExpenseVoucher;
use App\Models\FundTransfer;
use App\Models\JournalEntryLine;
use App\Models\SupplierPayment;
use App\Support\DecimalMath;
use App\Support\SourceDocument;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The cash book and the bank book.
 *
 * Every figure here is derived from **posted journal lines** and nothing else. There is no
 * stored balance, no cache and no per-module arithmetic: a supplier payment, a customer
 * receipt, an expense voucher, a fund transfer and a hand-written journal all appear in this
 * report for one reason only — each posted a journal line to the account. Adding a future
 * module that moves money requires no change here at all.
 *
 * That is also why the book reconciles to the general ledger by construction rather than by
 * effort: both read the same lines.
 *
 *   Money In  = a DEBIT to the cash/bank account   (an asset increasing)
 *   Money Out = a CREDIT to the cash/bank account  (an asset decreasing)
 *
 * The running balance is calculated per request and **never persisted**. It is a presentation
 * of the lines, not a fact about the account.
 *
 * See docs/ACCOUNTING_RULES.md §22.
 */
class CashBankBookService
{
    /**
     * Which document each journal source maps to, for naming the source in the book.
     *
     * @var array<class-string, array{0: string, 1: string}> source class => [label, number column]
     */
    private const SOURCES = [
        SupplierPayment::class => ['Supplier payment', 'payment_number'],
        CustomerReceipt::class => ['Customer receipt', 'receipt_number'],
        ExpenseVoucher::class => ['Expense voucher', 'voucher_number'],
        FundTransfer::class => ['Fund transfer', 'transfer_number'],
    ];

    public function __construct(private readonly AccountingBalanceService $balances) {}

    /**
     * The book for one cash or bank account.
     *
     * Everything before `$from` is folded into the opening balance, so the running balance is
     * true rather than starting from an arbitrary zero part-way through the year.
     *
     * @return array{
     *     opening_balance: string,
     *     rows: list<array<string, mixed>>,
     *     money_in_total: string,
     *     money_out_total: string,
     *     closing_balance: string
     * }
     */
    public function book(CashBankAccount $configuration, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $accountId = $configuration->account_id;

        $opening = $from === null
            ? DecimalMath::cost('0')
            : $this->balances->netBalance($accountId, Carbon::instance($from)->copy()->subDay());

        $lines = $this->postedLines($accountId, $from, $to);
        $documents = $this->resolveSourceDocuments($lines);

        $running = $opening;
        $moneyIn = DecimalMath::zero();
        $moneyOut = DecimalMath::zero();
        $rows = [];

        foreach ($lines as $line) {
            $debit = DecimalMath::cost(DecimalMath::of($line->debit));
            $credit = DecimalMath::cost(DecimalMath::of($line->credit));

            // An asset: a debit puts money in, a credit takes it out.
            $running = DecimalMath::cost(DecimalMath::add(DecimalMath::sub($running, $credit), $debit));
            $moneyIn = DecimalMath::add($moneyIn, $debit);
            $moneyOut = DecimalMath::add($moneyOut, $credit);

            $entry = $line->entry;
            $source = $this->describeSource($entry?->source_type, $entry?->source_id, $documents);

            $rows[] = [
                'id' => $line->id,
                'date' => $entry?->journal_date?->toDateString(),
                'journal_entry_id' => $line->journal_entry_id,
                'journal_number' => $entry?->journal_number,
                'source_label' => $source['label'],
                'source_number' => $source['number'],
                'source_type' => $entry?->source_type,
                'source_id' => $entry?->source_id,
                'description' => $line->description ?? $entry?->description,
                'money_in' => $debit,
                'money_out' => $credit,
                // Presentation only. Never stored.
                'running_balance' => $running,
            ];
        }

        return [
            'opening_balance' => $opening,
            'rows' => $rows,
            'money_in_total' => DecimalMath::cost($moneyIn),
            'money_out_total' => DecimalMath::cost($moneyOut),
            'closing_balance' => $running,
        ];
    }

    /**
     * Every configured cash and bank account with its derived balance.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     cash_total: string,
     *     bank_total: string,
     *     combined_total: string
     * }
     */
    public function summary(?CarbonInterface $asOf = null, bool $activeOnly = true): array
    {
        $configurations = CashBankAccount::query()
            ->with('account')
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->get()
            ->sortBy(fn (CashBankAccount $configuration): string => (string) $configuration->account?->code)
            ->values();

        $asOfDate = $asOf === null ? null : Carbon::instance($asOf);

        $rows = [];
        $cash = DecimalMath::zero();
        $bank = DecimalMath::zero();

        foreach ($configurations as $configuration) {
            $account = $configuration->account;

            // Derived every time, from posted journal lines. Never stored anywhere.
            $balance = $account === null
                ? DecimalMath::cost('0')
                : $this->balances->balance($account, $asOfDate);

            $configuration->isCash()
                ? $cash = DecimalMath::add($cash, $balance)
                : $bank = DecimalMath::add($bank, $balance);

            $rows[] = [
                'id' => $configuration->id,
                'display_name' => $configuration->display_name,
                'kind' => $configuration->account_kind->value,
                'kind_label' => $configuration->account_kind->label(),
                'account_id' => $configuration->account_id,
                'account_code' => $account?->code,
                'account_name' => $account?->name,
                'bank_name' => $configuration->bank_name,
                'branch_name' => $configuration->branch_name,
                'account_number' => $configuration->account_number,
                'is_active' => $configuration->is_active,
                'balance' => $balance,
            ];
        }

        $cash = DecimalMath::cost($cash);
        $bank = DecimalMath::cost($bank);

        return [
            'rows' => $rows,
            'cash_total' => $cash,
            'bank_total' => $bank,
            'combined_total' => DecimalMath::cost(DecimalMath::add($cash, $bank)),
        ];
    }

    /**
     * The combined cash and bank position — one number.
     *
     * A fund transfer must leave this unchanged, which is the cheapest possible proof that it
     * was recorded as a transfer and not as something else.
     */
    public function combinedBalance(?CarbonInterface $asOf = null): string
    {
        return $this->summary($asOf, activeOnly: false)['combined_total'];
    }

    /**
     * Whether the book agrees with the general ledger, exactly.
     *
     * It always should: both derive from the same posted lines. The check exists because an
     * invariant nobody verifies is only a belief.
     */
    public function reconciles(CashBankAccount $configuration, ?CarbonInterface $asOf = null): bool
    {
        $account = $configuration->account;

        if ($account === null) {
            return false;
        }

        $book = $this->book($configuration, null, $asOf);
        $ledger = $this->balances->netBalance($account->id, $asOf === null ? null : Carbon::instance($asOf));

        return DecimalMath::compare($book['closing_balance'], $ledger) === 0;
    }

    /**
     * Posted lines touching one account, in journal order.
     *
     * Only entries that reached the books are counted — POSTED, and REVERSED (whose own lines
     * remain posted and are cancelled out by the reversing entry's lines).
     *
     * @return Collection<int, JournalEntryLine>
     */
    private function postedLines(int $accountId, ?CarbonInterface $from, ?CarbonInterface $to): Collection
    {
        return JournalEntryLine::query()
            ->where('journal_entry_lines.account_id', $accountId)
            ->whereHas('entry', function (Builder $entry) use ($from, $to): void {
                $entry->whereIn('status', [
                    JournalEntryStatus::Posted->value,
                    JournalEntryStatus::Reversed->value,
                ]);

                if ($from !== null) {
                    $entry->whereOnOrAfter('journal_date', Carbon::instance($from)->toDateString());
                }

                if ($to !== null) {
                    $entry->whereOnOrBefore('journal_date', Carbon::instance($to)->toDateString());
                }
            })
            ->with('entry:id,journal_number,journal_date,description,status,source_type,source_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.journal_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.id')
            ->select('journal_entry_lines.*')
            ->get();
    }

    /**
     * Load the source documents named by these lines, one query per document type.
     *
     * @param  Collection<int, JournalEntryLine>  $lines
     * @return array<string, array<int, string>> source class => [id => document number]
     */
    private function resolveSourceDocuments(Collection $lines): array
    {
        $byType = [];

        foreach ($lines as $line) {
            $type = $line->entry?->source_type;
            $id = $line->entry?->source_id;

            if ($type === null || $id === null || ! isset(self::SOURCES[$type])) {
                continue;
            }

            $byType[$type][] = $id;
        }

        $documents = [];

        foreach ($byType as $type => $ids) {
            [, $column] = self::SOURCES[$type];

            $documents[$type] = $type::query()
                ->whereIn('id', array_unique($ids))
                ->pluck($column, 'id')
                ->all();
        }

        return $documents;
    }

    /**
     * Name the document behind a line, so the book can be read without opening each journal.
     *
     * @param  array<string, array<int, string>>  $documents
     * @return array{label: string, number: string|null}
     */
    private function describeSource(?string $type, ?int $id, array $documents): array
    {
        if ($type === null) {
            return ['label' => __('Manual journal'), 'number' => null];
        }

        if (! isset(self::SOURCES[$type])) {
            // A source this report has not been taught about still appears, named by its
            // document type rather than its document number.
            return ['label' => SourceDocument::label($type), 'number' => null];
        }

        [$label] = self::SOURCES[$type];

        return [
            'label' => __($label),
            'number' => $id === null ? null : ($documents[$type][$id] ?? null),
        ];
    }

    /**
     * @return array<string, string> kind value => label
     */
    public function kinds(): array
    {
        return collect(CashBankAccountKind::cases())
            ->mapWithKeys(fn (CashBankAccountKind $kind): array => [$kind->value => $kind->label()])
            ->all();
    }
}
