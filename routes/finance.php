<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\AccountingSetupController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryActionController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\Payables\ApAgingController;
use App\Http\Controllers\Payables\GrniReconciliationController;
use App\Http\Controllers\Payables\SupplierInvoiceActionController;
use App\Http\Controllers\Payables\SupplierInvoiceController;
use App\Http\Controllers\Payables\SupplierPaymentActionController;
use App\Http\Controllers\Payables\SupplierPaymentController;
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
