<?php

namespace App\Models;

use Database\Factories\SalesInvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billed portion of one delivered line.
 *
 * The **delivery line stays authoritative** for quantity, pricing basis and money. This line
 * only records how much of that delivered value is being billed now, which is what makes
 * partial and multi-invoice billing possible without ever recomputing a price.
 *
 * `product_id` is copied for reporting; the revenue account is resolved from the product's
 * mapping at posting time and is never chosen by a user.
 */
class SalesInvoiceLine extends Model
{
    /** @use HasFactory<SalesInvoiceLineFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_invoice_id',
        'delivery_line_id',
        'product_id',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * @return BelongsTo<DeliveryLine, $this>
     */
    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryLine::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
