<?php

namespace App\Models;

use App\Enums\PricingBasis;
use App\Support\DecimalMath;
use Database\Factories\SalesOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    /** @use HasFactory<SalesOrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_order_id',
        'product_id',
        'quantity',
        'secondary_quantity',
        'pricing_basis',
        'unit_price',
        'line_total',
        'delivered_quantity',
        'delivered_secondary_quantity',
    ];

    protected function casts(): array
    {
        return [
            'pricing_basis' => PricingBasis::class,
            'quantity' => 'decimal:3',
            'secondary_quantity' => 'decimal:3',
            'unit_price' => 'decimal:6',
            'line_total' => 'decimal:6',
            'delivered_quantity' => 'decimal:3',
            'delivered_secondary_quantity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Quantity still outstanding on this line, never below zero. */
    public function outstandingQuantity(): string
    {
        $outstanding = DecimalMath::sub(
            DecimalMath::of($this->quantity),
            DecimalMath::of($this->delivered_quantity),
        );

        return DecimalMath::isNegative($outstanding)
            ? DecimalMath::zero(DecimalMath::QUANTITY_SCALE)
            : DecimalMath::quantity($outstanding);
    }

    public function isFullyDelivered(): bool
    {
        return DecimalMath::compare(
            DecimalMath::of($this->delivered_quantity),
            DecimalMath::of($this->quantity),
        ) >= 0;
    }
}
