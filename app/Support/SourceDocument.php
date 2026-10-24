<?php

namespace App\Support;

use App\Models\CustomerReceipt;
use App\Models\Delivery;
use App\Models\ExpenseVoucher;
use App\Models\FinancialYearClosing;
use App\Models\FundTransfer;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\ProductionOrder;
use App\Models\SalesInvoice;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Support\Str;

/**
 * The reader's name for a document type held in a `source_type` column.
 *
 * Screens say "Supplier payment" — never `SupplierPayment` or `App\Models\SupplierPayment`.
 * The name is translated for display only and must never be saved.
 */
final class SourceDocument
{
    /** @var array<class-string, string> */
    private const LABELS = [
        GoodsReceipt::class => 'Goods receipt',
        Delivery::class => 'Delivery',
        ProductionOrder::class => 'Production',
        SupplierInvoice::class => 'Supplier invoice',
        SupplierPayment::class => 'Supplier payment',
        SalesInvoice::class => 'Sales invoice',
        CustomerReceipt::class => 'Customer receipt',
        ExpenseVoucher::class => 'Expense voucher',
        FundTransfer::class => 'Fund transfer',
        JournalEntry::class => 'Journal entry',
        FinancialYearClosing::class => 'Year-end closing',
    ];

    /**
     * The display name for a source type; `$none` names a record with no source document.
     */
    public static function label(?string $sourceType, string $none = 'Manual'): string
    {
        if ($sourceType === null || $sourceType === '') {
            return __($none);
        }

        // A type not listed here still reads as words ("Lot consolidation"), not as a class name.
        return __(self::LABELS[$sourceType] ?? Str::ucfirst(Str::lower(Str::headline(class_basename($sourceType)))));
    }
}
