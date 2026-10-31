<?php

use App\Enums\Permission;
use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\AccountingSetupController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryActionController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\Accounting\YearEndClosingController;
use App\Http\Controllers\Advances\CustomerAdvanceController;
use App\Http\Controllers\Advances\SupplierAdvanceController;
use App\Http\Controllers\Cash\BankReconciliationController;
use App\Http\Controllers\Cash\CashBankAccountController;
use App\Http\Controllers\Cash\CashBankBookController;
use App\Http\Controllers\Cash\ChequeController;
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
use App\Http\Controllers\Receivables\SalesCreditNoteController;
use App\Http\Controllers\Receivables\SalesInvoiceActionController;
use App\Http\Controllers\Receivables\SalesInvoiceController;
use App\Http\Controllers\Receivables\UnbilledDeliveryController;
use App\Http\Controllers\Reports\BalanceSheetController;
use App\Http\Controllers\Reports\CashBankPositionController;
use App\Http\Controllers\Reports\CashFlowController;
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
 * because they are business events with their own rules — and each has its own permission,
 * so the person who drafts a document need not be the one who posts or reverses it.
 * See docs/AUTHORIZATION_RULES.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('finance/accounts', AccountController::class)
        ->parameters(['accounts' => 'account'])
        ->names('accounts')
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::FinanceView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::FinanceAccountsManage->middleware());

    Route::resource('finance/journals', JournalEntryController::class)
        ->parameters(['journals' => 'journal'])
        ->names('journals')
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::FinanceView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::FinanceJournalCreate->middleware());

    Route::patch('finance/journals/{journal}/post', [JournalEntryActionController::class, 'post'])
        ->name('journals.post')
        ->can(Permission::FinanceJournalPost);
    Route::patch('finance/journals/{journal}/reverse', [JournalEntryActionController::class, 'reverse'])
        ->name('journals.reverse')
        ->can(Permission::FinanceJournalReverse);
    Route::patch('finance/journals/{journal}/cancel', [JournalEntryActionController::class, 'cancel'])
        ->name('journals.cancel')
        ->can(Permission::FinanceJournalCancel);

    Route::get('finance/general-ledger', [GeneralLedgerController::class, 'index'])
        ->name('general-ledger.index')
        ->can(Permission::FinanceView);

    Route::get('finance/trial-balance', [TrialBalanceController::class, 'index'])
        ->name('trial-balance.index')
        ->can(Permission::FinanceView);

    // ---------------------------------------------------------- accounts payable
    Route::resource('finance/supplier-invoices', SupplierInvoiceController::class)
        ->parameters(['supplier-invoices' => 'supplier_invoice'])
        ->names('supplier-invoices')
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::ApView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::ApInvoiceCreate->middleware());

    Route::get('finance/supplier-invoices/{supplier_invoice}/print', [SupplierInvoiceController::class, 'print'])
        ->name('supplier-invoices.print')
        ->can(Permission::ApView);
    Route::patch('finance/supplier-invoices/{supplier_invoice}/post', [SupplierInvoiceActionController::class, 'post'])
        ->name('supplier-invoices.post')
        ->can(Permission::ApInvoicePost);
    Route::patch('finance/supplier-invoices/{supplier_invoice}/reverse', [SupplierInvoiceActionController::class, 'reverse'])
        ->name('supplier-invoices.reverse')
        ->can(Permission::ApInvoiceReverse);
    Route::patch('finance/supplier-invoices/{supplier_invoice}/cancel', [SupplierInvoiceActionController::class, 'cancel'])
        ->name('supplier-invoices.cancel')
        ->can(Permission::ApInvoiceCancel);

    Route::resource('finance/supplier-payments', SupplierPaymentController::class)
        ->parameters(['supplier-payments' => 'supplier_payment'])
        ->names('supplier-payments')
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], Permission::ApView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::ApPaymentCreate->middleware());

    Route::patch('finance/supplier-payments/{supplier_payment}/post', [SupplierPaymentActionController::class, 'post'])
        ->name('supplier-payments.post')
        ->can(Permission::ApPaymentPost);
    Route::patch('finance/supplier-payments/{supplier_payment}/reverse', [SupplierPaymentActionController::class, 'reverse'])
        ->name('supplier-payments.reverse')
        ->can(Permission::ApPaymentReverse);
    Route::patch('finance/supplier-payments/{supplier_payment}/cancel', [SupplierPaymentActionController::class, 'cancel'])
        ->name('supplier-payments.cancel')
        ->can(Permission::ApPaymentCancel);

    Route::get('finance/ap-aging', [ApAgingController::class, 'index'])->name('ap-aging.index')
        ->can(Permission::ApView);
    Route::get('finance/grni-reconciliation', [GrniReconciliationController::class, 'index'])
        ->name('grni-reconciliation.index')
        ->can(Permission::ApView);

    // ------------------------------------------------------- accounts receivable
    Route::resource('finance/sales-invoices', SalesInvoiceController::class)
        ->parameters(['sales-invoices' => 'sales_invoice'])
        ->names('sales-invoices')
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::ArView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::ArInvoiceCreate->middleware());

    Route::get('finance/sales-invoices/{sales_invoice}/print', [SalesInvoiceController::class, 'print'])
        ->name('sales-invoices.print')
        ->can(Permission::ArView);
    Route::patch('finance/sales-invoices/{sales_invoice}/post', [SalesInvoiceActionController::class, 'post'])
        ->name('sales-invoices.post')
        ->can(Permission::ArInvoicePost);
    Route::patch('finance/sales-invoices/{sales_invoice}/reverse', [SalesInvoiceActionController::class, 'reverse'])
        ->name('sales-invoices.reverse')
        ->can(Permission::ArInvoiceReverse);
    Route::patch('finance/sales-invoices/{sales_invoice}/cancel', [SalesInvoiceActionController::class, 'cancel'])
        ->name('sales-invoices.cancel')
        ->can(Permission::ArInvoiceCancel);

    Route::resource('finance/customer-receipts', CustomerReceiptController::class)
        ->parameters(['customer-receipts' => 'customer_receipt'])
        ->names('customer-receipts')
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], Permission::ArView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::ArReceiptCreate->middleware());

    Route::patch('finance/customer-receipts/{customer_receipt}/post', [CustomerReceiptActionController::class, 'post'])
        ->name('customer-receipts.post')
        ->can(Permission::ArReceiptPost);
    Route::patch('finance/customer-receipts/{customer_receipt}/reverse', [CustomerReceiptActionController::class, 'reverse'])
        ->name('customer-receipts.reverse')
        ->can(Permission::ArReceiptReverse);
    Route::patch('finance/customer-receipts/{customer_receipt}/cancel', [CustomerReceiptActionController::class, 'cancel'])
        ->name('customer-receipts.cancel')
        ->can(Permission::ArReceiptCancel);

    // Customer advances — docs/ACCOUNTING_RULES.md §34.
    Route::get('finance/customer-advances', [CustomerAdvanceController::class, 'index'])
        ->name('customer-advances.index')
        ->can(Permission::ArView);
    Route::get('finance/customer-advances/create', [CustomerAdvanceController::class, 'create'])
        ->name('customer-advances.create')
        ->can(Permission::ArAdvanceRecord);
    Route::post('finance/customer-advances', [CustomerAdvanceController::class, 'store'])
        ->name('customer-advances.store')
        ->can(Permission::ArAdvanceRecord);
    Route::get('finance/customer-advances/{customerAdvance}', [CustomerAdvanceController::class, 'show'])
        ->name('customer-advances.show')
        ->can(Permission::ArView);
    Route::patch('finance/customer-advances/{customerAdvance}/reverse', [CustomerAdvanceController::class, 'reverse'])
        ->name('customer-advances.reverse')
        ->can(Permission::ArAdvanceReverse);
    Route::post('finance/customer-advances/{customerAdvance}/applications', [CustomerAdvanceController::class, 'apply'])
        ->name('customer-advances.apply')
        ->can(Permission::ArAdvanceRecord);
    Route::patch('finance/customer-advance-applications/{customerAdvanceApplication}/reverse', [CustomerAdvanceController::class, 'reverseApplication'])
        ->name('customer-advances.reverse-application')
        ->can(Permission::ArAdvanceReverse);
    Route::post('finance/customer-advances/{customerAdvance}/refunds', [CustomerAdvanceController::class, 'refund'])
        ->name('customer-advances.refund')
        ->can(Permission::ArAdvanceRecord);
    Route::patch('finance/customer-advance-refunds/{customerAdvanceRefund}/reverse', [CustomerAdvanceController::class, 'reverseRefund'])
        ->name('customer-advances.reverse-refund')
        ->can(Permission::ArAdvanceReverse);
    // Supplier advances — docs/ACCOUNTING_RULES.md §34.
    Route::get('finance/supplier-advances', [SupplierAdvanceController::class, 'index'])
        ->name('supplier-advances.index')
        ->can(Permission::ApView);
    Route::get('finance/supplier-advances/create', [SupplierAdvanceController::class, 'create'])
        ->name('supplier-advances.create')
        ->can(Permission::ApAdvanceRecord);
    Route::post('finance/supplier-advances', [SupplierAdvanceController::class, 'store'])
        ->name('supplier-advances.store')
        ->can(Permission::ApAdvanceRecord);
    Route::get('finance/supplier-advances/{supplierAdvance}', [SupplierAdvanceController::class, 'show'])
        ->name('supplier-advances.show')
        ->can(Permission::ApView);
    Route::patch('finance/supplier-advances/{supplierAdvance}/reverse', [SupplierAdvanceController::class, 'reverse'])
        ->name('supplier-advances.reverse')
        ->can(Permission::ApAdvanceReverse);
    Route::post('finance/supplier-advances/{supplierAdvance}/applications', [SupplierAdvanceController::class, 'apply'])
        ->name('supplier-advances.apply')
        ->can(Permission::ApAdvanceRecord);
    Route::patch('finance/supplier-advance-applications/{supplierAdvanceApplication}/reverse', [SupplierAdvanceController::class, 'reverseApplication'])
        ->name('supplier-advances.reverse-application')
        ->can(Permission::ApAdvanceReverse);
    Route::post('finance/supplier-advances/{supplierAdvance}/refunds', [SupplierAdvanceController::class, 'refund'])
        ->name('supplier-advances.refund')
        ->can(Permission::ApAdvanceRecord);
    Route::patch('finance/supplier-advance-refunds/{supplierAdvanceRefund}/reverse', [SupplierAdvanceController::class, 'reverseRefund'])
        ->name('supplier-advances.reverse-refund')
        ->can(Permission::ApAdvanceReverse);
    // Discounts and quality allowances — docs/ACCOUNTING_RULES.md §33.
    Route::get('finance/credit-notes', [SalesCreditNoteController::class, 'index'])
        ->name('credit-notes.index')
        ->can(Permission::ArView);
    Route::get('finance/credit-notes/create', [SalesCreditNoteController::class, 'create'])
        ->name('credit-notes.create')
        ->can(Permission::ArCreditNoteRecord);
    Route::post('finance/credit-notes', [SalesCreditNoteController::class, 'store'])
        ->name('credit-notes.store')
        ->can(Permission::ArCreditNoteRecord);
    Route::get('finance/credit-notes/{creditNote}', [SalesCreditNoteController::class, 'show'])
        ->name('credit-notes.show')
        ->can(Permission::ArView);
    Route::patch('finance/credit-notes/{creditNote}/reverse', [SalesCreditNoteController::class, 'reverse'])
        ->name('credit-notes.reverse')
        ->can(Permission::ArCreditNoteReverse);
    Route::get('finance/ar-aging', [ArAgingController::class, 'index'])->name('ar-aging.index')
        ->can(Permission::ArView);
    Route::get('finance/unbilled-deliveries', [UnbilledDeliveryController::class, 'index'])
        ->name('unbilled-deliveries.index')
        ->can(Permission::ArView);

    // ------------------------------------------------------------ cash and bank
    // No destroy route: a configuration money has moved through is deactivated, and a
    // posted voucher or transfer is reversed. Nothing here is ever deleted.
    Route::resource('finance/cash-bank-accounts', CashBankAccountController::class)
        ->parameters(['cash-bank-accounts' => 'cashBankAccount'])
        ->names('cash-bank-accounts')
        ->except(['destroy', 'show'])
        ->middlewareFor(['index'], Permission::CashView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::CashAccountsManage->middleware());

    // Cheques and bank reconciliation — docs/ACCOUNTING_RULES.md §35-§36.
    Route::get('finance/cheques', [ChequeController::class, 'index'])
        ->name('cheques.index')
        ->can(Permission::CashView);
    Route::post('finance/cheques/clear', [ChequeController::class, 'clear'])
        ->name('cheques.clear')
        ->can(Permission::CashChequeManage);
    Route::post('finance/cheques/undo-clear', [ChequeController::class, 'undoClear'])
        ->name('cheques.undo-clear')
        ->can(Permission::CashChequeManage);
    Route::post('finance/cheques/bounce', [ChequeController::class, 'bounce'])
        ->name('cheques.bounce')
        ->can(Permission::CashChequeManage);
    Route::get('finance/bank-reconciliation', [BankReconciliationController::class, 'index'])
        ->name('bank-reconciliation.index')
        ->can(Permission::CashView);
    Route::post('finance/bank-reconciliation', [BankReconciliationController::class, 'store'])
        ->name('bank-reconciliation.store')
        ->can(Permission::CashReconcile);
    Route::patch('finance/bank-reconciliation/{bankReconciliation}/reverse', [BankReconciliationController::class, 'reverse'])
        ->name('bank-reconciliation.reverse')
        ->can(Permission::CashReconcile);

    Route::get('finance/cash-bank-book', [CashBankBookController::class, 'index'])
        ->name('cash-bank-book.index')
        ->can(Permission::CashView);

    Route::resource('finance/expense-vouchers', ExpenseVoucherController::class)
        ->parameters(['expense-vouchers' => 'expenseVoucher'])
        ->names('expense-vouchers')
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::CashView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::CashExpenseCreate->middleware());

    Route::patch('finance/expense-vouchers/{expenseVoucher}/post', [ExpenseVoucherActionController::class, 'post'])
        ->name('expense-vouchers.post')
        ->can(Permission::CashExpensePost);
    Route::patch('finance/expense-vouchers/{expenseVoucher}/reverse', [ExpenseVoucherActionController::class, 'reverse'])
        ->name('expense-vouchers.reverse')
        ->can(Permission::CashExpenseReverse);
    Route::patch('finance/expense-vouchers/{expenseVoucher}/cancel', [ExpenseVoucherActionController::class, 'cancel'])
        ->name('expense-vouchers.cancel')
        ->can(Permission::CashExpenseCancel);

    Route::resource('finance/fund-transfers', FundTransferController::class)
        ->parameters(['fund-transfers' => 'fundTransfer'])
        ->names('fund-transfers')
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], Permission::CashView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::CashTransferCreate->middleware());

    Route::patch('finance/fund-transfers/{fundTransfer}/post', [FundTransferActionController::class, 'post'])
        ->name('fund-transfers.post')
        ->can(Permission::CashTransferPost);
    Route::patch('finance/fund-transfers/{fundTransfer}/reverse', [FundTransferActionController::class, 'reverse'])
        ->name('fund-transfers.reverse')
        ->can(Permission::CashTransferReverse);
    Route::patch('finance/fund-transfers/{fundTransfer}/cancel', [FundTransferActionController::class, 'cancel'])
        ->name('fund-transfers.cancel')
        ->can(Permission::CashTransferCancel);

    // ---------------------------------------- financial statements and reports
    // All read-only. Every figure derives from a posted ledger; nothing here writes.
    Route::get('finance/dashboard', [FinanceDashboardController::class, 'index'])
        ->name('finance-dashboard.index')
        ->can(Permission::ReportsManagement);

    Route::get('finance/reports/profit-loss', [ProfitLossController::class, 'index'])
        ->name('reports.profit-loss')
        ->can(Permission::ReportsFinance);
    Route::get('finance/reports/balance-sheet', [BalanceSheetController::class, 'index'])
        ->name('reports.balance-sheet')
        ->can(Permission::ReportsFinance);
    Route::get('finance/reports/inventory-valuation', [InventoryValuationController::class, 'index'])
        ->name('reports.inventory-valuation')
        ->can(Permission::ReportsInventory);
    Route::get('finance/reports/inventory-gl-reconciliation', [InventoryGlReconciliationController::class, 'index'])
        ->name('reports.inventory-gl-reconciliation')
        ->can(Permission::ReportsInventory);
    Route::get('finance/reports/cash-flow', [CashFlowController::class, 'index'])
        ->name('reports.cash-flow')
        ->can(Permission::ReportsFinance);
    Route::get('finance/reports/cash-bank-position', [CashBankPositionController::class, 'index'])
        ->name('reports.cash-bank-position')
        ->can(Permission::ReportsFinance);
    Route::get('finance/reports/product-profitability', [ProductProfitabilityController::class, 'index'])
        ->name('reports.product-profitability')
        ->can(Permission::ReportsManagement);

    Route::get('finance/accounting-setup', [AccountingSetupController::class, 'index'])
        ->name('accounting-setup.index')
        ->can(Permission::FinanceView);
    Route::patch('finance/accounting-setup/settings', [AccountingSetupController::class, 'updateSettings'])
        ->name('accounting-setup.settings')
        ->can(Permission::FinanceSetupManage);
    Route::patch('finance/accounting-setup/products/{product}', [AccountingSetupController::class, 'updateMapping'])
        ->name('accounting-setup.mapping')
        ->can(Permission::FinanceSetupManage);

    Route::get('finance/fiscal-periods', [FiscalPeriodController::class, 'index'])
        ->name('fiscal-periods.index')
        ->can(Permission::FinanceView);
    Route::post('finance/fiscal-periods', [FiscalPeriodController::class, 'store'])
        ->name('fiscal-periods.store')
        ->can(Permission::FinancePeriodManage);
    Route::patch('finance/fiscal-periods/{fiscalPeriod}/close', [FiscalPeriodController::class, 'close'])
        ->name('fiscal-periods.close')
        ->can(Permission::FinancePeriodManage);

    // Year-end closing — see docs/ACCOUNTING_RULES.md §24.
    Route::get('finance/year-end', [YearEndClosingController::class, 'index'])
        ->name('year-end.index')
        ->can(Permission::FinanceView);
    Route::post('finance/year-end', [YearEndClosingController::class, 'store'])
        ->name('year-end.store')
        ->can(Permission::FinanceYearEndClose);
    Route::patch('finance/year-end/{closing}/reverse', [YearEndClosingController::class, 'reverse'])
        ->name('year-end.reverse')
        ->can(Permission::FinanceYearEndReverse);
});
