<?php

namespace App\Actions\Loans;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Support\DailyDocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Loan document numbers: LOAN-YYYYMMDD-000001 for loans and LPAY-YYYYMMDD-000001 for payments.
 * Like every other reference here, the number encodes no business meaning.
 */
class GenerateLoanReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forLoan(CarbonInterface $date): string
    {
        return $this->sequence->next('LOAN', Loan::class, 'loan_number', Carbon::instance($date));
    }

    public function forPayment(CarbonInterface $date): string
    {
        return $this->sequence->next('LPAY', LoanPayment::class, 'payment_number', Carbon::instance($date));
    }
}
