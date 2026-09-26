<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Finance\ExpenseVoucherController;
use App\Http\Controllers\Api\V1\Finance\FundTransferController;
use Illuminate\Support\Facades\Route;

/*
 * Cash and bank documents. Each route checks the same permission as its web counterpart
 * (routes/finance.php). Route parameter names match the web routes so the shared form
 * requests resolve them identically. No delete routes — correct by cancel or reverse.
 * Fund transfers have no update, exactly as on the website.
 *
 * The two `options` routes have no web twin: they return what the matching web create form
 * offers, and require the permission to create that document.
 */
Route::get('expense-vouchers', [ExpenseVoucherController::class, 'index'])->name('expense-vouchers.index')->can(Permission::CashView);
Route::get('expense-vouchers/options', [ExpenseVoucherController::class, 'options'])->name('expense-vouchers.options')->can(Permission::CashExpenseCreate);
Route::post('expense-vouchers', [ExpenseVoucherController::class, 'store'])->name('expense-vouchers.store')->can(Permission::CashExpenseCreate);
Route::get('expense-vouchers/{expenseVoucher}', [ExpenseVoucherController::class, 'show'])->name('expense-vouchers.show')->can(Permission::CashView);
Route::put('expense-vouchers/{expenseVoucher}', [ExpenseVoucherController::class, 'update'])->name('expense-vouchers.update')->can(Permission::CashExpenseCreate);
Route::post('expense-vouchers/{expenseVoucher}/post', [ExpenseVoucherController::class, 'post'])->name('expense-vouchers.post')->can(Permission::CashExpensePost);
Route::post('expense-vouchers/{expenseVoucher}/reverse', [ExpenseVoucherController::class, 'reverse'])->name('expense-vouchers.reverse')->can(Permission::CashExpenseReverse);
Route::post('expense-vouchers/{expenseVoucher}/cancel', [ExpenseVoucherController::class, 'cancel'])->name('expense-vouchers.cancel')->can(Permission::CashExpenseCancel);

Route::get('fund-transfers', [FundTransferController::class, 'index'])->name('fund-transfers.index')->can(Permission::CashView);
Route::get('fund-transfers/options', [FundTransferController::class, 'options'])->name('fund-transfers.options')->can(Permission::CashTransferCreate);
Route::post('fund-transfers', [FundTransferController::class, 'store'])->name('fund-transfers.store')->can(Permission::CashTransferCreate);
Route::get('fund-transfers/{fundTransfer}', [FundTransferController::class, 'show'])->name('fund-transfers.show')->can(Permission::CashView);
Route::post('fund-transfers/{fundTransfer}/post', [FundTransferController::class, 'post'])->name('fund-transfers.post')->can(Permission::CashTransferPost);
Route::post('fund-transfers/{fundTransfer}/reverse', [FundTransferController::class, 'reverse'])->name('fund-transfers.reverse')->can(Permission::CashTransferReverse);
Route::post('fund-transfers/{fundTransfer}/cancel', [FundTransferController::class, 'cancel'])->name('fund-transfers.cancel')->can(Permission::CashTransferCancel);
