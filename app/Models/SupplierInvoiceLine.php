<?php

namespace App\Models;

use App\Enums\SupplierInvoiceLineType;
use Database\Factories\SupplierInvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charge on a supplier invoice.
 *
 * Either it clears part of a goods receipt's accrual (`goods_receipt_id`), or it charges an
 * expense account directly (`debit_account_id`). Never both — the line type decides which,
 * and the posting action enforces it.
 */
class SupplierInvoiceLine extends Model
{
    /** @use HasFactory<SupplierInvoiceLineFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'line_type',
        'goods_receipt_id',
        'debit_account_id',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => SupplierInvoiceLineType::class,
            'amount' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }
}
