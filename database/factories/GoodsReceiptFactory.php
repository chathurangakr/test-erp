<?php

namespace Database\Factories;

use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'grn_number' => 'GRN-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'supplier_id' => Supplier::factory(),
            'purchase_order_id' => null,
            'warehouse_id' => Warehouse::factory(),
            'receipt_date' => now()->toDateString(),
            'supplier_delivery_reference' => null,
            'transport_cost' => '0.000000',
            'loading_cost' => '0.000000',
            'other_cost' => '0.000000',
            'notes' => null,
            'status' => GoodsReceiptStatus::Draft,
            'created_by' => null,
        ];
    }
}
