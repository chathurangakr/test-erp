<?php

namespace App\Services\Telegram;

use App\Enums\LoanFunding;
use App\Models\AssetMaintenance;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceApplication;
use App\Models\CustomerAdvanceRefund;
use App\Models\CustomerBagMovement;
use App\Models\CustomerReceipt;
use App\Models\Delivery;
use App\Models\ExpenseVoucher;
use App\Models\FixedAsset;
use App\Models\FundTransfer;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\PayrollRun;
use App\Models\ProductionOrder;
use App\Models\SalaryAdvance;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockDamage;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierAdvanceRefund;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;

/**
 * Which documents the transactions bot reports, and which of their changes count.
 *
 * An alert is an event in a document's life read from its status, never a call in each action:
 * posted (DRAFT → POSTED, or created already posted), reversed (→ REVERSED), and for a fixed asset
 * sold / sale_reversed. Everything else — drafts, cancellations, an invoice becoming PAID — is no
 * alert. Journals the system writes for a document are not reported (the document is); a manual
 * journal is. A loan opened by buying an asset on credit is reported as the asset.
 * See docs/TELEGRAM.md.
 */
final class TransactionAlertCatalog
{
    /** @var array<class-string<Model>, string> */
    public const MODELS = [
        GoodsReceipt::class => 'goods_receipts',
        Delivery::class => 'deliveries',
        ProductionOrder::class => 'production',
        SalesInvoice::class => 'sales_invoices',
        CustomerReceipt::class => 'customer_receipts',
        SalesReturn::class => 'sales_returns',
        SalesCreditNote::class => 'credit_notes',
        CustomerAdvance::class => 'customer_advances',
        CustomerAdvanceApplication::class => 'customer_advances',
        CustomerAdvanceRefund::class => 'customer_advances',
        SupplierAdvance::class => 'supplier_advances',
        SupplierAdvanceApplication::class => 'supplier_advances',
        SupplierAdvanceRefund::class => 'supplier_advances',
        CustomerBagMovement::class => 'customer_bags',
        SupplierInvoice::class => 'supplier_invoices',
        SupplierPayment::class => 'supplier_payments',
        ExpenseVoucher::class => 'expense_vouchers',
        FundTransfer::class => 'fund_transfers',
        JournalEntry::class => 'journals',
        StockDamage::class => 'stock_damages',
        SalaryAdvance::class => 'salary_advances',
        PayrollRun::class => 'payrolls',
        Loan::class => 'loans',
        LoanPayment::class => 'loan_payments',
        FixedAsset::class => 'fixed_assets',
        AssetMaintenance::class => 'asset_maintenance',
    ];

    /**
     * Each kind of alert with its name on the settings page (English; the page translates it).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'goods_receipts' => 'Goods received',
            'deliveries' => 'Deliveries',
            'production' => 'Production (dehusking, husk chipping, copra)',
            'sales_invoices' => 'Sales invoices',
            'customer_receipts' => 'Customer receipts',
            'sales_returns' => 'Sales returns',
            'credit_notes' => 'Discounts and quality allowances',
            'customer_advances' => 'Customer advances received and applied',
            'supplier_advances' => 'Supplier advances paid and applied',
            'customer_bags' => 'Bags given to and returned by customers',
            'supplier_invoices' => 'Supplier invoices',
            'supplier_payments' => 'Supplier payments',
            'expense_vouchers' => 'Expense vouchers',
            'fund_transfers' => 'Cash and bank transfers',
            'journals' => 'Manual journals',
            'stock_damages' => 'Damaged stock',
            'salary_advances' => 'Salary advances',
            'payrolls' => 'Salaries paid',
            'loans' => 'Loans',
            'loan_payments' => 'Loan payments',
            'fixed_assets' => 'Fixed assets bought and sold',
            'asset_maintenance' => 'Asset maintenance',
        ];
    }

    public static function typeOf(Model $model): ?string
    {
        return self::MODELS[$model::class] ?? null;
    }

    /** The event a newly created document reports: one created already posted. */
    public static function createdEvent(Model $model): ?string
    {
        if (! self::isReported($model)) {
            return null;
        }

        return in_array(self::status($model), ['POSTED', 'IN_USE'], true) ? 'posted' : null;
    }

    /** The event a change of status reports, if any. */
    public static function updatedEvent(Model $model): ?string
    {
        if (! self::isReported($model) || ! $model->wasChanged('status')) {
            return null;
        }

        $from = (string) $model->getRawOriginal('status');
        $to = self::status($model);

        return match (true) {
            $to === 'POSTED' && $from === 'DRAFT' => 'posted',
            $to === 'REVERSED' => 'reversed',
            $model instanceof FixedAsset && $from === 'IN_USE' && $to === 'SOLD' => 'sold',
            $model instanceof FixedAsset && $from === 'SOLD' && $to === 'IN_USE' => 'sale_reversed',
            default => null,
        };
    }

    /**
     * What the message needs that the document may lose before it is sent: an asset sale can be
     * undone (which clears it), so both the sale and its undoing keep the sale's figures.
     *
     * @return array<string, mixed>|null
     */
    public static function context(Model $model, string $event): ?array
    {
        if ($model instanceof FixedAsset && $event === 'sold') {
            return [
                'sold_date' => $model->sold_date?->toDateString(),
                'sale_price' => (string) $model->sale_price,
                'buyer' => $model->buyer,
            ];
        }

        if ($model instanceof FixedAsset && $event === 'sale_reversed') {
            return [
                'sold_date' => substr((string) $model->getRawOriginal('sold_date'), 0, 10),
                'sale_price' => (string) $model->getRawOriginal('sale_price'),
                'buyer' => $model->getRawOriginal('buyer'),
            ];
        }

        return null;
    }

    private static function isReported(Model $model): bool
    {
        return match (true) {
            // Only journals typed by hand; a reversing entry is reported as its original's reversal.
            $model instanceof JournalEntry => $model->source_type === null && $model->reversal_of_id === null,
            $model instanceof Loan => $model->funding !== LoanFunding::Asset,
            default => self::typeOf($model) !== null,
        };
    }

    private static function status(Model $model): string
    {
        return (string) ($model->getAttributes()['status'] ?? '');
    }
}
