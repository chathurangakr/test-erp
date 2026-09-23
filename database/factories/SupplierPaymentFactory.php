<?php

namespace Database\Factories;

use App\Enums\SupplierPaymentStatus;
use App\Models\Account;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPayment>
 */
class SupplierPaymentFactory extends Factory
{
    protected $model = SupplierPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_number' => 'PAY-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'supplier_id' => Supplier::factory(),
            'payment_date' => now()->toDateString(),
            'fiscal_period_id' => null,
            'payment_account_id' => Account::factory(),
            'reference' => null,
            'amount' => '1000.000000',
            'status' => SupplierPaymentStatus::Draft,
            'notes' => null,
            'created_by' => null,
        ];
    }
}
