<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryActionController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\TrialBalanceController;
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

    Route::get('finance/fiscal-periods', [FiscalPeriodController::class, 'index'])
        ->name('fiscal-periods.index');
    Route::post('finance/fiscal-periods', [FiscalPeriodController::class, 'store'])
        ->name('fiscal-periods.store');
    Route::patch('finance/fiscal-periods/{fiscalPeriod}/close', [FiscalPeriodController::class, 'close'])
        ->name('fiscal-periods.close');
});
