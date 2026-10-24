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
     * @param  array{lender: string, purpose?: string|null, has_interest?: bool, installment_amount?: string|null, due_day?: int|null, notes?: string|null}  $data
     */
    public function handle(Loan $loan, array $data): Loan
    {
        $loan->update([
            'lender' => $data['lender'],
            'purpose' => $data['purpose'] ?? null,
            'has_interest' => (bool) ($data['has_interest'] ?? $loan->has_interest),
            'installment_amount' => filled($data['installment_amount'] ?? null) ? DecimalMath::money(DecimalMath::of((string) $data['installment_amount'])) : null,
            'due_day' => $data['due_day'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return $loan->refresh();
    }
}
