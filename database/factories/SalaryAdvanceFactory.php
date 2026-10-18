<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PayrollDocumentStatus;
use App\Models\CashBankAccount;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare record for screens and access tests. Real advances are paid through
 * App\Actions\Payroll\RecordSalaryAdvance, which also writes the journal.
 *
 * @extends Factory<SalaryAdvance>
 */
class SalaryAdvanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'advance_number' => 'ADV-20260901-'.fake()->unique()->numerify('######'),
            'employee_id' => Employee::factory(),
            'advance_date' => '2026-09-01',
            'amount' => '1000.00',
            'cash_bank_account_id' => CashBankAccount::factory(),
            'payment_method' => PaymentMethod::Cash,
            'status' => PayrollDocumentStatus::Posted,
        ];
    }
}
