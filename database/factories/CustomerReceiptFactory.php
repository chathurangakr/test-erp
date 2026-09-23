<?php

namespace Database\Factories;

use App\Enums\CustomerReceiptStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReceipt>
 */
class CustomerReceiptFactory extends Factory
{
    protected $model = CustomerReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'receipt_number' => 'REC-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory(),
            'receipt_date' => now()->toDateString(),
            'fiscal_period_id' => null,
            'receipt_account_id' => Account::factory(),
            'reference' => null,
            'amount' => '1000.000000',
            'status' => CustomerReceiptStatus::Draft,
            'notes' => null,
            'created_by' => null,
        ];
    }
}
