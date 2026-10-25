<?php

namespace Database\Factories;

use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A posted advance of 10,000. Real advances are written by RecordCustomerAdvance, with their journal.
 *
 * @extends Factory<CustomerAdvance>
 */
class CustomerAdvanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'advance_number' => 'CADV-20260920-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory(),
            'advance_date' => '2026-09-20',
            'amount' => '10000',
            'cash_bank_account_id' => CashBankAccount::factory(),
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => AdvanceStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
