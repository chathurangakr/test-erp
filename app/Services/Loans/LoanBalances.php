<?php

namespace App\Services\Loans;

use App\Enums\LoanDocumentStatus;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;

/**
 * What a loan still owes and when its next installment falls due — derived every time, never
 * stored. A loan has no balance column, just as an account has none. See docs/LOANS.md.
 */
class LoanBalances
{
    /** The principal the standing payments have paid off. */
    public function principalPaid(Loan $loan): string
    {
        return DecimalMath::cost((string) LoanPayment::query()
            ->where('loan_id', $loan->id)
            ->where('status', LoanDocumentStatus::Posted->value)
            ->sum('principal_amount'));
    }

    /** The interest the standing payments have paid. */
    public function interestPaid(Loan $loan): string
    {
        return DecimalMath::cost((string) LoanPayment::query()
            ->where('loan_id', $loan->id)
            ->where('status', LoanDocumentStatus::Posted->value)
            ->sum('interest_amount'));
    }

    /** What is still owed: the amount borrowed less the principal paid. A reversed loan owes nothing. */
    public function outstanding(Loan $loan): string
    {
        if ($loan->status !== LoanDocumentStatus::Posted) {
            return DecimalMath::cost('0');
        }

        return DecimalMath::cost(DecimalMath::sub(DecimalMath::of((string) $loan->amount), $this->principalPaid($loan)));
    }

    public function isPaidOff(Loan $loan): bool
    {
        return ! DecimalMath::isPositive($this->outstanding($loan));
    }

    /**
     * The first due date on or after $from: the loan's due day of that month (the month's last
     * day when it is shorter), else of the next month. Null when no due day is set.
     */
    public function nextDueDate(Loan $loan, CarbonImmutable $from): ?CarbonImmutable
    {
        if ($loan->due_day === null) {
            return null;
        }

        $candidate = $this->dueDateIn($loan, $from->startOfMonth());

        return $candidate->lessThan($from->startOfDay()) ? $this->dueDateIn($loan, $from->startOfMonth()->addMonthNoOverflow()) : $candidate;
    }

    /**
     * Whether the installment due on $due is already paid: a standing payment made in the month
     * leading up to it (after the previous due date, up to this one).
     */
    public function isInstallmentPaid(Loan $loan, CarbonImmutable $due): bool
    {
        $previous = $this->dueDateIn($loan, $due->startOfMonth()->subMonthNoOverflow());

        return LoanPayment::query()
            ->where('loan_id', $loan->id)
            ->where('status', LoanDocumentStatus::Posted->value)
            ->whereDate('payment_date', '>', $previous->toDateString())
            ->whereDate('payment_date', '<=', $due->toDateString())
            ->exists();
    }

    private function dueDateIn(Loan $loan, CarbonImmutable $month): CarbonImmutable
    {
        return $month->setDay(min((int) $loan->due_day, $month->daysInMonth))->startOfDay();
    }
}
