<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_number' => 'DN-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory(),
            'sales_order_id' => null,
            'warehouse_id' => Warehouse::factory(),
            'delivery_date' => now()->toDateString(),
            'customer_reference' => null,
            'vehicle_number' => null,
            'driver_name' => null,
            'notes' => null,
            'status' => DeliveryStatus::Draft,
            'created_by' => null,
        ];
    }
}
