<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\CashBankAccountKind;
use App\Models\Account;
use App\Models\CashBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashBankAccount>
 */
class CashBankAccountFactory extends Factory
{
    protected $model = CashBankAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory()->state([
                'account_type' => AccountType::Asset,
                'is_control_account' => false,
                'allow_manual_posting' => true,
                'is_active' => true,
            ]),
            'account_kind' => CashBankAccountKind::Bank,
            'display_name' => fake()->company().' Account',
            'bank_name' => fake()->company(),
            'branch_name' => fake()->city(),
            'account_number' => (string) fake()->randomNumber(8, true),
            'is_active' => true,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn (): array => [
            'account_kind' => CashBankAccountKind::Cash,
            'display_name' => 'Cash on Hand',
            'bank_name' => null,
            'branch_name' => null,
            'account_number' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Configure an account that already exists in the chart.
     */
    public function forAccount(Account $account, CashBankAccountKind $kind = CashBankAccountKind::Bank): static
    {
        return $this->state(fn (): array => [
            'account_id' => $account->id,
            'account_kind' => $kind,
            'display_name' => $account->name,
        ]);
    }
}
