<?php

namespace App\Models;

use Database\Factories\CustomerReceiptAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one receipt settled one invoice.
 *
 * Allocations of a REVERSED receipt stay on the record but stop counting toward what an
 * invoice has been paid — history is preserved, the balance is restored.
 */
class CustomerReceiptAllocation extends Model
{
    /** @use HasFactory<CustomerReceiptAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_receipt_id',
        'sales_invoice_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
