<?php

namespace App\Actions\Cash;

use App\Actions\Accounting\GenerateJournalReference;
use App\Actions\Accounting\PostJournalEntry;
use App\Enums\ExpenseVoucherStatus;
use App\Enums\JournalEntryStatus;
use App\Models\CashBankAccount;
use App\Models\ExpenseVoucher;
use App\Models\ExpenseVoucherLine;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts an expense voucher — an expense paid immediately out of cash or the bank.
 *
 *   Dr each expense account
 *       Cr the chosen cash / bank account, for the total
 *
 * **No payable is raised.** That is the whole difference between this document and a supplier
 * invoice: nothing is owed afterwards, because the money has already gone. A supplier may be
 * named for reporting, and naming one changes nothing about the journal.
 *
 * **No inventory moves.** Not a quantity, not a ledger row, not a weighted average. An
 * expense voucher is a general-ledger document only.
 *
 * Every rule is checked before anything is written, and the whole thing runs in one database
 * transaction, so a voucher can never be marked posted while its journal failed.
 */
class PostExpenseVoucher
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
    ) {}

    public function handle(ExpenseVoucher $voucher, ?int $userId = null): ExpenseVoucher
    {
        return DB::transaction(function () use ($voucher, $userId): ExpenseVoucher {
            // Re-read under lock so two concurrent posts cannot both pass the draft check.
            $locked = ExpenseVoucher::query()
                ->whereKey($voucher->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $period = $this->resolvePeriod($locked);
            $configuration = $this->resolveCashBankAccount($locked);

            $lines = $locked->lines()->with('expenseAccount')->orderBy('id')->get();
            $total = $this->validateLines($lines);

            $entry = JournalEntry::create([
                'journal_number' => $this->references->forJournalEntry($locked->voucher_date),
                'journal_date' => $locked->voucher_date->toDateString(),
                'fiscal_period_id' => $period->id,
                'source_type' => ExpenseVoucher::class,
                'source_id' => $locked->getKey(),
                'description' => __('Expense voucher :reference paid from :account', [
                    'reference' => $locked->voucher_number,
                    'account' => $configuration->display_name,
                ]),
                'status' => JournalEntryStatus::Draft,
                'created_by' => $userId ?? auth()->id(),
            ]);

            // One debit per expense charged...
            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line->expense_account_id,
                    'description' => $line->description ?? $locked->description,
                    'debit' => DecimalMath::cost(DecimalMath::of($line->amount)),
                    'credit' => DecimalMath::cost('0'),
                ]);
            }

            // ...and one credit for the money that left, so the two sides agree exactly.
            $entry->lines()->create([
                'account_id' => $configuration->account_id,
                'description' => $locked->payee() ?? $locked->reference,
                'debit' => DecimalMath::cost('0'),
                'credit' => $total,
            ]);

            $posted = $this->post->handle($entry->refresh(), $userId);

            $locked->forceFill([
                'fiscal_period_id' => $period->id,
                'status' => ExpenseVoucherStatus::Posted,
                'journal_entry_id' => $posted->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            return $locked->refresh()->load(['lines.expenseAccount', 'cashBankAccount.account', 'journalEntry.lines']);
        });
    }

    private function assertPostable(ExpenseVoucher $voucher): void
    {
        if (! $voucher->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft expense voucher can be posted. :reference is :status.', [
                    'reference' => $voucher->voucher_number,
                    'status' => $voucher->status->label(),
                ]),
            ]);
        }

        if ($voucher->journal_entry_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Expense voucher :reference has already been posted.', [
                    'reference' => $voucher->voucher_number,
                ]),
            ]);
        }
    }

    private function resolvePeriod(ExpenseVoucher $voucher): FiscalPeriod
    {
        $period = FiscalPeriod::openFor($voucher->voucher_date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'voucher_date' => __('There is no open fiscal period covering :date, so this voucher cannot be posted.', [
                    'date' => $voucher->voucher_date->toDateString(),
                ]),
            ]);
        }

        return $period;
    }

    /**
     * The cash or bank account the money left — checked end to end, configuration included.
     */
    private function resolveCashBankAccount(ExpenseVoucher $voucher): CashBankAccount
    {
        $configuration = $voucher->cashBankAccount()->with('account')->first();

        if ($configuration === null) {
            throw ValidationException::withMessages([
                'cash_bank_account_id' => __('The selected cash or bank account does not exist.'),
            ]);
        }

        $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('an expense voucher'));

        return $configuration;
    }

    /**
     * Check every line and return the exact total that must be credited.
     *
     * @param  Collection<int, ExpenseVoucherLine>  $lines
     */
    private function validateLines(Collection $lines): string
    {
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('An expense voucher must charge at least one expense.'),
            ]);
        }

        $total = DecimalMath::zero();

        foreach ($lines as $index => $line) {
            $account = $line->expenseAccount;

            if ($account === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.expense_account_id" => __('The selected expense account does not exist.'),
                ]);
            }

            $this->policy->assertExpenseAccount($account, "lines.{$index}.expense_account_id");

            $amount = DecimalMath::cost(DecimalMath::of($line->amount));

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.amount" => __('Every expense line must be greater than zero.'),
                ]);
            }

            $total = DecimalMath::add($total, $amount);
        }

        return DecimalMath::cost($total);
    }
}
