<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\AccountingSetupController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryActionController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\Cash\CashBankAccountController;
use App\Http\Controllers\Cash\CashBankBookController;
use App\Http\Controllers\Cash\ExpenseVoucherActionController;
use App\Http\Controllers\Cash\ExpenseVoucherController;
use App\Http\Controllers\Cash\FundTransferActionController;
use App\Http\Controllers\Cash\FundTransferController;
use App\Http\Controllers\Payables\ApAgingController;
use App\Http\Controllers\Payables\GrniReconciliationController;
use App\Http\Controllers\Payables\SupplierInvoiceActionController;
use App\Http\Controllers\Payables\SupplierInvoiceController;
use App\Http\Controllers\Payables\SupplierPaymentActionController;
use App\Http\Controllers\Payables\SupplierPaymentController;
use App\Http\Controllers\Receivables\ArAgingController;
use App\Http\Controllers\Receivables\CustomerReceiptActionController;
use App\Http\Controllers\Receivables\CustomerReceiptController;
use App\Http\Controllers\Receivables\SalesInvoiceActionController;
use App\Http\Controllers\Receivables\SalesInvoiceController;
use App\Http\Controllers\Receivables\UnbilledDeliveryController;
use App\Http\Controllers\Reports\BalanceSheetController;
use App\Http\Controllers\Reports\CashBankPositionController;
use App\Http\Controllers\Reports\FinanceDashboardController;
use App\Http\Controllers\Reports\InventoryGlReconciliationController;
use App\Http\Controllers\Reports\InventoryValuationController;
use App\Http\Controllers\Reports\ProductProfitabilityController;
use App\Http\Controllers\Reports\ProfitLossController;
use Illuminate\Support\Facades\Route;

/*
 * Finance — the accounting foundation.
 *
 * There is deliberately NO destroy route anywhere here. An account with history is
 * deactivated, never deleted; a posted journal is reversed, never removed; a fiscal period
 * is closed, never dropped. Accounting history is permanent.
 *
 * Posting, reversing and cancelling are separate PATCH actions rather than form saves,
 * because they are business events with their own rules.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('finance/accounts', AccountController::class)
        ->parameters(['accounts' => 'account'])
        ->names('accounts')
        ->except(['destroy']);

    Route::resource('finance/journals', JournalEntryController::class)
        ->parameters(['journals' => 'journal'])
        ->names('journals')
        ->except(['destroy']);

    Route::patch('finance/journals/{journal}/post', [JournalEntryActionController::class, 'post'])
        ->name('journals.post');
    Route::patch('finance/journals/{journal}/reverse', [JournalEntryActionController::class, 'reverse'])
        ->name('journals.reverse');
    Route::patch('finance/journals/{journal}/cancel', [JournalEntryActionController::class, 'cancel'])
        ->name('journals.cancel');

    Route::get('finance/general-ledger', [GeneralLedgerController::class, 'index'])
        ->name('general-ledger.index');

    Route::get('finance/trial-balance', [TrialBalanceController::class, 'index'])
        ->name('trial-balance.index');

    // ---------------------------------------------------------- accounts payable
    Route::resource('finance/supplier-invoices', SupplierInvoiceController::class)
        ->parameters(['supplier-invoices' => 'supplier_invoice'])
        ->names('supplier-invoices')
        ->except(['destroy']);

    Route::patch('finance/supplier-invoices/{supplier_invoice}/post', [SupplierInvoiceActionController::class, 'post'])
        ->name('supplier-invoices.post');
    Route::patch('finance/supplier-invoices/{supplier_invoice}/reverse', [SupplierInvoiceActionController::class, 'reverse'])
        ->name('supplier-invoices.reverse');
    Route::patch('finance/supplier-invoices/{supplier_invoice}/cancel', [SupplierInvoiceActionController::class, 'cancel'])
        ->name('supplier-invoices.cancel');

    Route::resource('finance/supplier-payments', SupplierPaymentController::class)
        ->parameters(['supplier-payments' => 'supplier_payment'])
        ->names('supplier-payments')
        ->only(['index', 'create', 'store', 'show']);

    Route::patch('finance/supplier-payments/{supplier_payment}/post', [SupplierPaymentActionController::class, 'post'])
        ->name('supplier-payments.post');
    Route::patch('finance/supplier-payments/{supplier_payment}/reverse', [SupplierPaymentActionController::class, 'reverse'])
        ->name('supplier-payments.reverse');
    Route::patch('finance/supplier-payments/{supplier_payment}/cancel', [SupplierPaymentActionController::class, 'cancel'])
        ->name('supplier-payments.cancel');

    Route::get('finance/ap-aging', [ApAgingController::class, 'index'])->name('ap-aging.index');
    Route::get('finance/grni-reconciliation', [GrniReconciliationController::class, 'index'])
        ->name('grni-reconciliation.index');

    // ------------------------------------------------------- accounts receivable
    Route::resource('finance/sales-invoices', SalesInvoiceController::class)
        ->parameters(['sales-invoices' => 'sales_invoice'])
        ->names('sales-invoices')
        ->except(['destroy']);

    Route::patch('finance/sales-invoices/{sales_invoice}/post', [SalesInvoiceActionController::class, 'post'])
        ->name('sales-invoices.post');
    Route::patch('finance/sales-invoices/{sales_invoice}/reverse', [SalesInvoiceActionController::class, 'reverse'])
        ->name('sales-invoices.reverse');
    Route::patch('finance/sales-invoices/{sales_invoice}/cancel', [SalesInvoiceActionController::class, 'cancel'])
        ->name('sales-invoices.cancel');

    Route::resource('finance/customer-receipts', CustomerReceiptController::class)
        ->parameters(['customer-receipts' => 'customer_receipt'])
        ->names('customer-receipts')
        ->only(['index', 'create', 'store', 'show']);

    Route::patch('finance/customer-receipts/{customer_receipt}/post', [CustomerReceiptActionController::class, 'post'])
        ->name('customer-receipts.post');
    Route::patch('finance/customer-receipts/{customer_receipt}/reverse', [CustomerReceiptActionController::class, 'reverse'])
        ->name('customer-receipts.reverse');
    Route::patch('finance/customer-receipts/{customer_receipt}/cancel', [CustomerReceiptActionController::class, 'cancel'])
        ->name('customer-receipts.cancel');

    Route::get('finance/ar-aging', [ArAgingController::class, 'index'])->name('ar-aging.index');
    Route::get('finance/unbilled-deliveries', [UnbilledDeliveryController::class, 'index'])
        ->name('unbilled-deliveries.index');

    // ------------------------------------------------------------ cash and bank
    // No destroy route: a configuration money has moved through is deactivated, and a
    // posted voucher or transfer is reversed. Nothing here is ever deleted.
    Route::resource('finance/cash-bank-accounts', CashBankAccountController::class)
        ->parameters(['cash-bank-accounts' => 'cashBankAccount'])
        ->names('cash-bank-accounts')
        ->except(['destroy', 'show']);

    Route::get('finance/cash-bank-book', [CashBankBookController::class, 'index'])
        ->name('cash-bank-book.index');

    Route::resource('finance/expense-vouchers', ExpenseVoucherController::class)
        ->parameters(['expense-vouchers' => 'expenseVoucher'])
        ->names('expense-vouchers')
        ->except(['destroy']);

    Route::patch('finance/expense-vouchers/{expenseVoucher}/post', [ExpenseVoucherActionController::class, 'post'])
        ->name('expense-vouchers.post');
    Route::patch('finance/expense-vouchers/{expenseVoucher}/reverse', [ExpenseVoucherActionController::class, 'reverse'])
        ->name('expense-vouchers.reverse');
    Route::patch('finance/expense-vouchers/{expenseVoucher}/cancel', [ExpenseVoucherActionController::class, 'cancel'])
        ->name('expense-vouchers.cancel');

    Route::resource('finance/fund-transfers', FundTransferController::class)
        ->parameters(['fund-transfers' => 'fundTransfer'])
        ->names('fund-transfers')
        ->only(['index', 'create', 'store', 'show']);

    Route::patch('finance/fund-transfers/{fundTransfer}/post', [FundTransferActionController::class, 'post'])
        ->name('fund-transfers.post');
    Route::patch('finance/fund-transfers/{fundTransfer}/reverse', [FundTransferActionController::class, 'reverse'])
        ->name('fund-transfers.reverse');
    Route::patch('finance/fund-transfers/{fundTransfer}/cancel', [FundTransferActionController::class, 'cancel'])
        ->name('fund-transfers.cancel');

    // ---------------------------------------- financial statements and reports
    // All read-only. Every figure derives from a posted ledger; nothing here writes.
    Route::get('finance/dashboard', [FinanceDashboardController::class, 'index'])
        ->name('finance-dashboard.index');

    Route::get('finance/reports/profit-loss', [ProfitLossController::class, 'index'])
        ->name('reports.profit-loss');
    Route::get('finance/reports/balance-sheet', [BalanceSheetController::class, 'index'])
        ->name('reports.balance-sheet');
    Route::get('finance/reports/inventory-valuation', [InventoryValuationController::class, 'index'])
        ->name('reports.inventory-valuation');
    Route::get('finance/reports/inventory-gl-reconciliation', [InventoryGlReconciliationController::class, 'index'])
        ->name('reports.inventory-gl-reconciliation');
    Route::get('finance/reports/cash-bank-position', [CashBankPositionController::class, 'index'])
        ->name('reports.cash-bank-position');
    Route::get('finance/reports/product-profitability', [ProductProfitabilityController::class, 'index'])
        ->name('reports.product-profitability');

    Route::get('finance/accounting-setup', [AccountingSetupController::class, 'index'])
        ->name('accounting-setup.index');
    Route::patch('finance/accounting-setup/settings', [AccountingSetupController::class, 'updateSettings'])
        ->name('accounting-setup.settings');
    Route::patch('finance/accounting-setup/products/{product}', [AccountingSetupController::class, 'updateMapping'])
        ->name('accounting-setup.mapping');

    Route::get('finance/fiscal-periods', [FiscalPeriodController::class, 'index'])
        ->name('fiscal-periods.index');
    Route::post('finance/fiscal-periods', [FiscalPeriodController::class, 'store'])
        ->name('fiscal-periods.store');
    Route::patch('finance/fiscal-periods/{fiscalPeriod}/close', [FiscalPeriodController::class, 'close'])
        ->name('fiscal-periods.close');
});
