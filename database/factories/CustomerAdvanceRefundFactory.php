<?php

namespace Database\Factories;

use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceRefund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A posted refund of 500 from an advance. Real ones are written by RefundCustomerAdvance, with their journal.
 *
 * @extends Factory<CustomerAdvanceRefund>
 */
class CustomerAdvanceRefundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_advance_id' => CustomerAdvance::factory(),
            'refund_date' => '2026-09-22',
            'amount' => '500',
            'cash_bank_account_id' => CashBankAccount::factory(),
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => AdvanceStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
