<?php

use App\Enums\Permission;
use App\Http\Controllers\Loans\LoanController;
use Illuminate\Support\Facades\Route;

/*
 * Loans — what the factory owes, installments paid, reminders. Money is recorded in one step and
 * corrected by reversal; only a loan's description and reminder schedule are edited. There is no
 * destroy route. See docs/LOANS.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('loans', LoanController::class)
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::LoansView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::LoansManage->middleware());
    Route::patch('loans/{loan}/reverse', [LoanController::class, 'reverse'])
        ->name('loans.reverse')
        ->can(Permission::LoansReverse);
    Route::post('loans/{loan}/payments', [LoanController::class, 'storePayment'])
        ->name('loans.payments.store')
        ->can(Permission::LoansPaymentRecord);
    Route::patch('loan-payments/{loanPayment}/reverse', [LoanController::class, 'reversePayment'])
        ->name('loan-payments.reverse')
        ->can(Permission::LoansPaymentReverse);
});
