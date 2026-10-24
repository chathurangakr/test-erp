<?php

namespace Database\Factories;

use App\Enums\LoanDocumentStatus;
use App\Enums\LoanFunding;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare loan header, for screens and access tests. Real loans — which post a journal — are
 * made through App\Actions\Loans\RecordLoan.
 *
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_number' => 'LOAN-'.fake()->unique()->numerify('20260915-######'),
            'lender' => fake()->company(),
            'start_date' => '2026-09-01',
            'funding' => LoanFunding::Existing,
            'amount' => '100000.000000',
            'has_interest' => true,
            'installment_amount' => '10000.000000',
            'due_day' => 15,
            'status' => LoanDocumentStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
