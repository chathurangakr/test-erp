<?php

namespace Database\Factories;

use App\Enums\CustomerBagMovementKind;
use App\Enums\CustomerBagMovementStatus;
use App\Models\Customer;
use App\Models\CustomerBagMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare bag movement header, for screens and access tests. Real movements — which move stock —
 * are made through App\Actions\Sales\RecordCustomerBagMovement.
 *
 * @extends Factory<CustomerBagMovement>
 */
class CustomerBagMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movement_number' => 'BAG-'.fake()->unique()->numerify('20260915-######'),
            'movement_date' => '2026-09-15',
            'kind' => CustomerBagMovementKind::Given,
            'customer_id' => Customer::factory(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity' => '10.000',
            'status' => CustomerBagMovementStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
