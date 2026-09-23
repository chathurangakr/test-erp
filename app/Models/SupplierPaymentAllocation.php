<?php

namespace App\Models;

use Database\Factories\SupplierPaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment settled one invoice.
 *
 * Allocations of a REVERSED payment stay on the record but stop counting toward what an
 * invoice has been paid — history is preserved, the balance is restored.
 */
class SupplierPaymentAllocation extends Model
{
    /** @use HasFactory<SupplierPaymentAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_payment_id',
        'supplier_invoice_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<SupplierPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
