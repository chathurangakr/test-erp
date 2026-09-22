<?php

namespace App\Models;

use Database\Factories\ProductionOutputFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOutput extends Model
{
    /** @use HasFactory<ProductionOutputFactory> */
    use HasFactory;

    protected $fillable = [
        'production_order_id',
        'product_id',
        'lot_id',
        'quantity',
        'secondary_quantity',
        'allocation_percent',
        'allocated_cost',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            // The measured weight of an output that is also counted — raw shell. Never a
            // conversion of the primary quantity; no fixed NOS-to-KG relationship exists.
            'secondary_quantity' => 'decimal:3',
            'allocation_percent' => 'decimal:6',
            'allocated_cost' => 'decimal:6',
            'unit_cost' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
