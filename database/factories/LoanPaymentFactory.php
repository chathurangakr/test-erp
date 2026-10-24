<?php

namespace Database\Factories;

use App\Enums\LoanDocumentStatus;
use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use App\Models\Loan;
use App\Models\LoanPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare loan payment, for screens and access tests. Real payments — which post a journal — are
 * made through App\Actions\Loans\RecordLoanPayment.
 *
 * @extends Factory<LoanPayment>
 */
class LoanPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_number' => 'LPAY-'.fake()->unique()->numerify('20260915-######'),
            'loan_id' => Loan::factory(),
            'payment_date' => '2026-09-15',
            'amount' => '10000.000000',
            'interest_amount' => '1000.000000',
            'principal_amount' => '9000.000000',
            'cash_bank_account_id' => CashBankAccount::factory(),
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => LoanDocumentStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
