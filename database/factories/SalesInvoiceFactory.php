<?php

namespace Database\Factories;

use App\Enums\SalesInvoiceStatus;
use App\Models\Customer;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesInvoice>
 */
class SalesInvoiceFactory extends Factory
{
    protected $model = SalesInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_number' => 'SINV-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'fiscal_period_id' => null,
            'customer_reference' => null,
            'description' => null,
            'status' => SalesInvoiceStatus::Draft,
            'created_by' => null,
        ];
    }
}
