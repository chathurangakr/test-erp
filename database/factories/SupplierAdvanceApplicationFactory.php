<?php

namespace Database\Factories;

use App\Enums\AdvanceStatus;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A posted application of 1,000 of an advance to an invoice. Real ones are written by
 * ApplySupplierAdvance, with their journal.
 *
 * @extends Factory<SupplierAdvanceApplication>
 */
class SupplierAdvanceApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_advance_id' => SupplierAdvance::factory(),
            'supplier_invoice_id' => SupplierInvoice::factory(),
            'application_date' => '2026-09-21',
            'amount' => '1000',
            'status' => AdvanceStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
