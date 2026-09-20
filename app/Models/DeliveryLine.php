<?php

namespace App\Models;

use App\Enums\PricingBasis;
use App\Support\DecimalMath;
use Database\Factories\DeliveryLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryLine extends Model
{
    /** @use HasFactory<DeliveryLineFactory> */
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'sales_order_line_id',
        'product_id',
        'lot_id',
        'quantity',
        'secondary_quantity',
        'pricing_basis',
        'unit_price',
        'sales_value',
        'inventory_unit_cost',
        'cost_of_goods_sold',
    ];

    protected function casts(): array
    {
        return [
            'pricing_basis' => PricingBasis::class,
            'quantity' => 'decimal:3',
            'secondary_quantity' => 'decimal:3',
            'unit_price' => 'decimal:6',
            'sales_value' => 'decimal:6',
            'inventory_unit_cost' => 'decimal:6',
            'cost_of_goods_sold' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<SalesOrderLine, $this>
     */
    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    /**
     * The quantity revenue is calculated from, chosen by the pricing basis.
     *
     * Never typed independently — it is always one of the two recorded quantities.
     */
    public function sellingQuantity(): string
    {
        return $this->pricing_basis->isSecondary()
            ? DecimalMath::of($this->secondary_quantity ?? '0')
            : DecimalMath::of($this->quantity);
    }

    public function grossProfit(): ?string
    {
        if ($this->cost_of_goods_sold === null) {
            return null;
        }

        return DecimalMath::cost(
            DecimalMath::sub(DecimalMath::of($this->sales_value), DecimalMath::of($this->cost_of_goods_sold))
        );
    }

    /** Null until posted; 0 when the sale is worth nothing, never a division error. */
    public function grossMarginPercent(): ?string
    {
        $profit = $this->grossProfit();

        if ($profit === null) {
            return null;
        }

        $sales = DecimalMath::of($this->sales_value);

        if (DecimalMath::isZero($sales)) {
            return DecimalMath::round('0', 2);
        }

        return DecimalMath::round(DecimalMath::mul(DecimalMath::div($profit, $sales), '100'), 2);
    }
}
