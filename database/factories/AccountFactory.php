<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(AccountType::cases());

        return [
            'code' => (string) $this->faker->unique()->numberBetween(10000, 99999),
            'name' => $this->faker->words(3, true),
            'account_type' => $type,
            'parent_id' => null,
            'normal_balance' => $type->defaultNormalBalance(),
            'is_control_account' => false,
            'allow_manual_posting' => true,
            'is_active' => true,
            'description' => null,
        ];
    }

    public function ofType(AccountType $type): static
    {
        return $this->state(fn (): array => [
            'account_type' => $type,
            'normal_balance' => $type->defaultNormalBalance(),
        ]);
    }

    /** A control account: reconciled from a subsidiary ledger, never posted to by hand. */
    public function control(): static
    {
        return $this->state(fn (): array => [
            'is_control_account' => true,
            'allow_manual_posting' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function noManualPosting(): static
    {
        return $this->state(fn (): array => ['allow_manual_posting' => false]);
    }
}
