<?php

namespace Database\Factories;

use App\Enums\ExpenseVoucherStatus;
use App\Models\CashBankAccount;
use App\Models\ExpenseVoucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseVoucher>
 */
class ExpenseVoucherFactory extends Factory
{
    protected $model = ExpenseVoucher::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'voucher_number' => 'EXP-'.now()->format('Ymd').'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'voucher_date' => now()->toDateString(),
            'cash_bank_account_id' => CashBankAccount::factory(),
            'payee_name' => fake()->company(),
            'reference' => fake()->bothify('REF-####'),
            'description' => fake()->sentence(),
            'status' => ExpenseVoucherStatus::Draft,
        ];
    }
}
