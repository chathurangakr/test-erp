<?php

namespace Database\Factories;

use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPaymentAllocation>
 */
class SupplierPaymentAllocationFactory extends Factory
{
    protected $model = SupplierPaymentAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_payment_id' => SupplierPayment::factory(),
            'supplier_invoice_id' => SupplierInvoice::factory(),
            'amount' => '1000.000000',
        ];
    }
}
