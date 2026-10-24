<?php

namespace Database\Factories;

use App\Enums\SalesReturnSettlement;
use App\Enums\SalesReturnStatus;
use App\Models\Customer;
use App\Models\DeliveryLine;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare sales return header, for screens and access tests. Real returns — which move stock and
 * post a journal — are made through App\Actions\Sales\RecordSalesReturn.
 *
 * @extends Factory<SalesReturn>
 */
class SalesReturnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_number' => 'RET-'.fake()->unique()->numerify('20260915-######'),
            'return_date' => '2026-09-15',
            'customer_id' => Customer::factory(),
            'delivery_line_id' => DeliveryLine::factory(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'good_quantity' => '10.000',
            'damaged_quantity' => '0.000',
            'unit_price' => '100.000000',
            'credit_amount' => '1000.000000',
            'unit_cost' => '80.000000',
            'good_cost' => '800.000000',
            'damaged_cost' => '0.000000',
            'settlement' => SalesReturnSettlement::Credit,
            'original_invoice_id' => SalesInvoice::factory(),
            'credit_invoice_id' => null,
            'reason' => 'Returned',
            'status' => SalesReturnStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
