<?php

namespace App\Actions\Loans;

use App\Models\Loan;
use App\Support\DecimalMath;

/**
 * Corrects a loan's description and reminder schedule. Money figures never change here — a
 * wrong amount is corrected by reversing the loan.
 */
class UpdateLoan
{
    /**
     * @param  array{lender: string, purpose?: string|null, has_interest?: bool, interest_rate?: string|null, installment_amount?: string|null, due_day?: int|null, notes?: string|null}  $data
     */
    public function handle(Loan $loan, array $data): Loan
    {
        $loan->update([
            'lender' => $data['lender'],
            'purpose' => $data['purpose'] ?? null,
            'has_interest' => $hasInterest = (bool) ($data['has_interest'] ?? $loan->has_interest),
            'interest_rate' => $hasInterest && filled($data['interest_rate'] ?? null) ? (string) $data['interest_rate'] : null,
            'installment_amount' => filled($data['installment_amount'] ?? null) ? DecimalMath::money(DecimalMath::of((string) $data['installment_amount'])) : null,
            'due_day' => $data['due_day'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return $loan->refresh();
    }
}
