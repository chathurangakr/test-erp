<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceiptLine>
 */
class GoodsReceiptLineFactory extends Factory
{
    protected $model = GoodsReceiptLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'product_id' => Product::factory(),
            'quantity' => '100.000',
            'secondary_quantity' => null,
            'unit_price' => '10.000000',
            'material_cost' => '0.000000',
            'allocated_landed_cost' => '0.000000',
            'final_unit_cost' => '0.000000',
            'lot_id' => null,
            'supplier_lot_number' => null,
        ];
    }
}
