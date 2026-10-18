<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PayrollDocumentStatus;
use App\Models\CashBankAccount;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare record for screens and access tests. Real payrolls are paid through
 * App\Actions\Payroll\PayPayroll, which also writes the lines and the journal.
 *
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payroll_number' => 'PAY-20260801-'.fake()->unique()->numerify('######'),
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'pay_date' => '2026-08-01',
            'cash_bank_account_id' => CashBankAccount::factory(),
            'payment_method' => PaymentMethod::Cash,
            'status' => PayrollDocumentStatus::Posted,
        ];
    }
}
