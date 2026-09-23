<?php

namespace App\Actions\Cash;

use App\Actions\Accounting\GenerateJournalReference;
use App\Actions\Accounting\PostJournalEntry;
use App\Enums\FundTransferStatus;
use App\Enums\JournalEntryStatus;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Models\FundTransfer;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a fund transfer between two of the factory's own cash and bank accounts.
 *
 *   Dr destination cash / bank
 *       Cr source cash / bank
 *
 * The company is neither richer nor poorer afterwards: combined cash and bank is identical,
 * and the income statement is untouched. **Nothing is expensed, earned, owed or received** —
 * this is a balance-sheet event and nothing else, which is precisely why it must never be
 * recorded as a payment or a receipt.
 *
 * **No inventory moves.** Not a quantity, not a ledger row, not a weighted average.
 *
 * The two amounts are the same figure, used twice, so a transfer cannot drift out of balance.
 */
class PostFundTransfer
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
    ) {}

    public function handle(FundTransfer $transfer, ?int $userId = null): FundTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): FundTransfer {
            $locked = FundTransfer::query()
                ->whereKey($transfer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostable($locked);

            $period = $this->resolvePeriod($locked);

            $from = $this->resolveAccount($locked->from_cash_bank_account_id, 'from_cash_bank_account_id', __('the source of a transfer'));
            $to = $this->resolveAccount($locked->to_cash_bank_account_id, 'to_cash_bank_account_id', __('the destination of a transfer'));

            // Moving money to where it already is is not a transaction, and would post a
            // debit and a credit to the same account for no reason.
            if ($from->id === $to->id) {
                throw ValidationException::withMessages([
                    'to_cash_bank_account_id' => __('A transfer must be between two different cash or bank accounts.'),
                ]);
            }

            $amount = DecimalMath::cost(DecimalMath::of($locked->amount));

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages([
                    'amount' => __('The transfer amount must be greater than zero.'),
                ]);
            }

            $entry = JournalEntry::create([
                'journal_number' => $this->references->forJournalEntry($locked->transfer_date),
                'journal_date' => $locked->transfer_date->toDateString(),
                'fiscal_period_id' => $period->id,
                'source_type' => FundTransfer::class,
                'source_id' => $locked->getKey(),
                'description' => __('Fund transfer :reference from :from to :to', [
                    'reference' => $locked->transfer_number,
                    'from' => $from->display_name,
                    'to' => $to->display_name,
                ]),
                'status' => JournalEntryStatus::Draft,
                'created_by' => $userId ?? auth()->id(),
            ]);

            $entry->lines()->create([
                'account_id' => $to->account_id,
                'description' => __('Transfer in from :from', ['from' => $from->display_name]),
                'debit' => $amount,
                'credit' => DecimalMath::cost('0'),
            ]);

            $entry->lines()->create([
                'account_id' => $from->account_id,
                'description' => __('Transfer out to :to', ['to' => $to->display_name]),
                'debit' => DecimalMath::cost('0'),
                // The same figure, not a recalculation, so the two legs cannot disagree.
                'credit' => $amount,
            ]);

            $posted = $this->post->handle($entry->refresh(), $userId);

            $locked->forceFill([
                'fiscal_period_id' => $period->id,
                'status' => FundTransferStatus::Posted,
                'journal_entry_id' => $posted->getKey(),
                'posted_by' => $userId ?? auth()->id(),
                'posted_at' => now(),
            ])->save();

            return $locked->refresh()->load(['fromAccount.account', 'toAccount.account', 'journalEntry.lines']);
        });
    }

    private function assertPostable(FundTransfer $transfer): void
    {
        if (! $transfer->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft fund transfer can be posted. :reference is :status.', [
                    'reference' => $transfer->transfer_number,
                    'status' => $transfer->status->label(),
                ]),
            ]);
        }

        if ($transfer->journal_entry_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Fund transfer :reference has already been posted.', [
                    'reference' => $transfer->transfer_number,
                ]),
            ]);
        }
    }

    private function resolvePeriod(FundTransfer $transfer): FiscalPeriod
    {
        $period = FiscalPeriod::openFor($transfer->transfer_date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'transfer_date' => __('There is no open fiscal period covering :date, so this transfer cannot be posted.', [
                    'date' => $transfer->transfer_date->toDateString(),
                ]),
            ]);
        }

        return $period;
    }

    private function resolveAccount(?int $id, string $field, string $subject): CashBankAccount
    {
        $configuration = CashBankAccount::query()->with('account')->find($id);

        if ($configuration === null) {
            throw ValidationException::withMessages([
                $field => __('The selected cash or bank account does not exist.'),
            ]);
        }

        $this->policy->assertUsableCashBankAccount($configuration, $field, $subject);

        return $configuration;
    }
}
