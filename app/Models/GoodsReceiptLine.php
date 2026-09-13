<?php

namespace App\Models;

use Database\Factories\GoodsReceiptLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    /** @use HasFactory<GoodsReceiptLineFactory> */
    use HasFactory;

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_line_id',
        'product_id',
        'quantity',
        'secondary_quantity',
        'unit_price',
        'material_cost',
        'allocated_landed_cost',
        'final_unit_cost',
        'lot_id',
        'supplier_lot_number',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'secondary_quantity' => 'decimal:3',
            'unit_price' => 'decimal:6',
            'material_cost' => 'decimal:6',
            'allocated_landed_cost' => 'decimal:6',
            'final_unit_cost' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
