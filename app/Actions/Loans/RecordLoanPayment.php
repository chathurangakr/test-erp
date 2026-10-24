<?php

namespace App\Actions\Loans;

use App\Actions\Payroll\WritePayrollJournal;
use App\Enums\LoanDocumentStatus;
use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Loans\LoanBalances;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an installment actually paid — more or less than the usual amount — in one step:
 *
 *   Dr loans payable     principal (what reduces the loan = paid − interest)
 *   Dr loan interest     interest  (the figure on the bank's slip; 0 for an interest-free loan)
 *       Cr the cash / bank account   paid
 *
 * The principal can never take the loan below zero; the loan row is locked while that is
 * checked. See docs/LOANS.md.
 */
class RecordLoanPayment
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly LoanBalances $balances,
        private readonly GenerateLoanReference $references,
        private readonly WritePayrollJournal $journal,
    ) {}

    /**
     * @param  array{payment_date: string, amount: string, interest_amount?: string|null, cash_bank_account_id: int, payment_method: string, reference?: string|null}  $data
     */
    public function handle(Loan $loan, array $data, ?int $userId = null): LoanPayment
    {
        return DB::transaction(function () use ($loan, $data, $userId): LoanPayment {
            $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== LoanDocumentStatus::Posted) {
                throw ValidationException::withMessages(['status' => __('Payments cannot be recorded on a reversed loan.')]);
            }

            $date = CarbonImmutable::parse($data['payment_date'])->startOfDay();
            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));
            $interest = DecimalMath::money(DecimalMath::of((string) ($data['interest_amount'] ?? '0')));
            $principal = DecimalMath::money(DecimalMath::sub($amount, $interest));

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('The amount paid must be greater than zero.')]);
            }

            if (DecimalMath::isNegative($interest) || DecimalMath::isNegative($principal)) {
                throw ValidationException::withMessages(['interest_amount' => __('The interest cannot be more than the amount paid.')]);
            }

            if (! $locked->has_interest && DecimalMath::isPositive($interest)) {
                throw ValidationException::withMessages(['interest_amount' => __('This loan carries no interest.')]);
            }

            if ($date->lessThan(CarbonImmutable::parse($locked->start_date))) {
                throw ValidationException::withMessages(['payment_date' => __('A payment cannot be dated before the loan started.')]);
            }

            $outstanding = $this->balances->outstanding($locked);

            if (DecimalMath::compare($principal, $outstanding, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => __('Only :outstanding is still owed on this loan, but :principal of this payment would reduce it. Check the interest part.', [
                        'outstanding' => DecimalMath::money($outstanding),
                        'principal' => $principal,
                    ]),
                ]);
            }

            $method = PaymentMethod::from($data['payment_method']);
            $configuration = CashBankAccount::query()->with('account')->findOrFail($data['cash_bank_account_id']);
            $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('a loan payment'));
            $this->policy->assertMethodFitsAccount($method, $configuration, 'cash_bank_account_id');
            $loansAccount = $this->policy->loansAccount();
            $interestAccount = DecimalMath::isPositive($interest) ? $this->policy->loanInterestAccount() : null;
            $period = FiscalPeriod::openFor($date) ?? throw ValidationException::withMessages([
                'payment_date' => __('There is no open fiscal period covering :date. Open the period first.', ['date' => $date->toDateString()]),
            ]);

            $payment = LoanPayment::create([
                'payment_number' => $this->references->forPayment($date),
                'loan_id' => $locked->id,
                'payment_date' => $date->toDateString(),
                'amount' => $amount,
                'interest_amount' => $interest,
                'principal_amount' => $principal,
                'cash_bank_account_id' => $configuration->id,
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'status' => LoanDocumentStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->journal->handle(
                $payment,
                $date,
                $period,
                RecordText::of('Loan :reference installment paid to :lender (:payment)', [
                    'reference' => $locked->loan_number,
                    'lender' => $locked->lender,
                    'payment' => $payment->payment_number,
                ]),
                [
                    [$loansAccount->id, $principal, '0', $locked->lender],
                    [$interestAccount?->id ?? $loansAccount->id, $interest, '0', RecordText::of('Interest on :reference', ['reference' => $locked->loan_number])],
                    [$configuration->account_id, '0', $amount, $data['reference'] ?? null],
                ],
                $userId,
            );

            $payment->update(['journal_entry_id' => $journal->id]);

            return $payment->refresh();
        });
    }
}
