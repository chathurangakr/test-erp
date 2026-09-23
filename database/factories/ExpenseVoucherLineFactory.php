<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ExpenseVoucher;
use App\Models\ExpenseVoucherLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseVoucherLine>
 */
class ExpenseVoucherLineFactory extends Factory
{
    protected $model = ExpenseVoucherLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_voucher_id' => ExpenseVoucher::factory(),
            'expense_account_id' => Account::factory()->state([
                'account_type' => AccountType::Expense,
                'is_control_account' => false,
                'allow_manual_posting' => true,
                'is_active' => true,
            ]),
            'description' => fake()->sentence(3),
            'amount' => '10000.000000',
        ];
    }

    public function charging(Account $account, string $amount): static
    {
        return $this->state(fn (): array => [
            'expense_account_id' => $account->id,
            'amount' => $amount,
        ]);
    }
}
