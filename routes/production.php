<?php

use App\Http\Controllers\Production\DehuskingActionController;
use App\Http\Controllers\Production\DehuskingController;
use Illuminate\Support\Facades\Route;

/*
 * Production — dehusking only.
 *
 * Husk chipping and copra are deliberately not routed: their output weights are
 * operator-entered and their waste and joint-cost rules are unresolved.
 *
 * There are NO destroy routes. A draft may be edited or cancelled; a posted order is a
 * controlled document, corrected by reversal.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('production/dehusking', DehuskingController::class)
        ->parameters(['dehusking' => 'dehusking'])
        ->names('dehusking')
        ->except(['destroy']);

    Route::patch('production/dehusking/{dehusking}/post', [DehuskingActionController::class, 'post'])
        ->name('dehusking.post');
    Route::patch('production/dehusking/{dehusking}/reverse', [DehuskingActionController::class, 'reverse'])
        ->name('dehusking.reverse');
    Route::patch('production/dehusking/{dehusking}/cancel', [DehuskingActionController::class, 'cancel'])
        ->name('dehusking.cancel');
});
