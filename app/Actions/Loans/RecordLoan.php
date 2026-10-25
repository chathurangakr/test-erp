<?php

namespace App\Actions\Loans;

use App\Actions\Payroll\WritePayrollJournal;
use App\Enums\LoanDocumentStatus;
use App\Enums\LoanFunding;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Models\Loan;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a loan on the books, in one step:
 *
 *   RECEIVED   Dr the cash / bank account the money came into   Cr loans payable
 *   EXISTING   Dr opening balance equity                         Cr loans payable
 *              (a loan already running, at what is still owed)
 *
 * The usual installment and due day only drive reminders; each payment records what was
 * actually paid. See docs/LOANS.md.
 */
class RecordLoan
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly GenerateLoanReference $references,
        private readonly WritePayrollJournal $journal,
    ) {}

    /**
     * @param  array{lender: string, purpose?: string|null, start_date: string, funding: string, amount: string, cash_bank_account_id?: int|null, has_interest?: bool, installment_amount?: string|null, due_day?: int|null, notes?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): Loan
    {
        return DB::transaction(function () use ($data, $userId): Loan {
            $date = CarbonImmutable::parse($data['start_date'])->startOfDay();
            $funding = LoanFunding::from($data['funding']);
            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('The loan amount must be greater than zero.')]);
            }

            $loansAccount = $this->policy->loansAccount();
            $configuration = null;

            if ($funding === LoanFunding::Received) {
                $configuration = CashBankAccount::query()->with('account')->find($data['cash_bank_account_id'] ?? 0)
                    ?? throw ValidationException::withMessages(['cash_bank_account_id' => __('Choose the cash or bank account the loan was paid into.')]);
                $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('a loan'));
                $debitAccountId = $configuration->account_id;
            } else {
                $debitAccountId = $this->policy->openingBalanceAccount()->id;
            }

            // A loan already running may have started before the books began: it is brought in on
            // the day it is entered. Money received now needs its own date.
            $bookDate = $funding === LoanFunding::Existing ? FiscalPeriod::openingEntryDate($date) : $date;
            $period = FiscalPeriod::openFor($bookDate->toDateString()) ?? throw ValidationException::withMessages([
                'start_date' => __('There is no open fiscal period covering :date. Open the period first.', ['date' => $bookDate->toDateString()]),
            ]);

            $loan = Loan::create([
                'loan_number' => $this->references->forLoan($date),
                'lender' => $data['lender'],
                'purpose' => $data['purpose'] ?? null,
                'start_date' => $date->toDateString(),
                'funding' => $funding,
                'amount' => $amount,
                'cash_bank_account_id' => $configuration?->id,
                'has_interest' => $hasInterest = (bool) ($data['has_interest'] ?? true),
                'interest_rate' => $hasInterest && filled($data['interest_rate'] ?? null) ? (string) $data['interest_rate'] : null,
                'installment_amount' => filled($data['installment_amount'] ?? null) ? DecimalMath::money(DecimalMath::of((string) $data['installment_amount'])) : null,
                'due_day' => $data['due_day'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => LoanDocumentStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->journal->handle(
                $loan,
                $bookDate,
                $period,
                $funding === LoanFunding::Received
                    ? RecordText::of('Loan :reference received from :lender', ['reference' => $loan->loan_number, 'lender' => $loan->lender])
                    : RecordText::of('Loan :reference from :lender brought in, still owed', ['reference' => $loan->loan_number, 'lender' => $loan->lender]),
                [
                    [$debitAccountId, $amount, '0', null],
                    [$loansAccount->id, '0', $amount, $loan->lender],
                ],
                $userId,
            );

            $loan->update(['journal_entry_id' => $journal->id]);

            return $loan->refresh();
        });
    }
}
