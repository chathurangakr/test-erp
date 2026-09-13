<?php

namespace App\Actions\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Support\DailyDocumentSequence;
use Illuminate\Support\Carbon;

/**
 * Generates purchasing document numbers.
 *
 *   Purchase orders: PO-YYYYMMDD-000001
 *   Goods receipts:  GRN-YYYYMMDD-000001
 *
 * Like inventory references, these encode no business meaning — no supplier and no product
 * — so a document can be re-classified without its permanent number becoming misleading.
 */
class GeneratePurchasingReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forPurchaseOrder(?Carbon $date = null): string
    {
        return $this->sequence->next('PO', PurchaseOrder::class, 'po_number', $date);
    }

    public function forGoodsReceipt(?Carbon $date = null): string
    {
        return $this->sequence->next('GRN', GoodsReceipt::class, 'grn_number', $date);
    }
}
