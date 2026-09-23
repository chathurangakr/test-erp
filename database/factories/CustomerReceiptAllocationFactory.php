<?php

namespace Database\Factories;

use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReceiptAllocation>
 */
class CustomerReceiptAllocationFactory extends Factory
{
    protected $model = CustomerReceiptAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_receipt_id' => CustomerReceipt::factory(),
            'sales_invoice_id' => SalesInvoice::factory(),
            'amount' => '1000.000000',
        ];
    }
}
