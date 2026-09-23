<?php

namespace Database\Factories;

use App\Enums\FundTransferStatus;
use App\Models\CashBankAccount;
use App\Models\FundTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundTransfer>
 */
class FundTransferFactory extends Factory
{
    protected $model = FundTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'transfer_number' => 'TRF-'.now()->format('Ymd').'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'transfer_date' => now()->toDateString(),
            'from_cash_bank_account_id' => CashBankAccount::factory()->cash(),
            'to_cash_bank_account_id' => CashBankAccount::factory(),
            'amount' => '100000.000000',
            'reference' => fake()->bothify('TR-####'),
            'description' => fake()->sentence(),
            'status' => FundTransferStatus::Draft,
        ];
    }
}
