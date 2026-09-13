<?php

namespace App\Models;

use App\Support\DecimalMath;
use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'quantity',
        'unit_price',
        'line_total',
        'received_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:6',
            'line_total' => 'decimal:6',
            'received_quantity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Quantity still outstanding on this line, never below zero.
     */
    public function outstandingQuantity(): string
    {
        $outstanding = DecimalMath::sub(
            DecimalMath::of($this->quantity),
            DecimalMath::of($this->received_quantity),
        );

        return DecimalMath::isNegative($outstanding)
            ? DecimalMath::zero(DecimalMath::QUANTITY_SCALE)
            : DecimalMath::quantity($outstanding);
    }

    public function isFullyReceived(): bool
    {
        return DecimalMath::compare(
            DecimalMath::of($this->received_quantity),
            DecimalMath::of($this->quantity),
        ) >= 0;
    }
}
