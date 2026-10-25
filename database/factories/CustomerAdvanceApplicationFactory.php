<?php

namespace Database\Factories;

use App\Enums\AdvanceStatus;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceApplication;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A posted application of 1,000 of an advance to an invoice. Real ones are written by
 * ApplyCustomerAdvance, with their journal.
 *
 * @extends Factory<CustomerAdvanceApplication>
 */
class CustomerAdvanceApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_advance_id' => CustomerAdvance::factory(),
            'sales_invoice_id' => SalesInvoice::factory(),
            'application_date' => '2026-09-21',
            'amount' => '1000',
            'status' => AdvanceStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
