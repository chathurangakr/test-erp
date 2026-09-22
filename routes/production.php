<?php

use App\Http\Controllers\Production\CopraActionController;
use App\Http\Controllers\Production\CopraController;
use App\Http\Controllers\Production\DehuskingActionController;
use App\Http\Controllers\Production\DehuskingController;
use App\Http\Controllers\Production\HuskChippingActionController;
use App\Http\Controllers\Production\HuskChippingController;
use Illuminate\Support\Facades\Route;

/*
 * Production — dehusking, husk chipping and copra.
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

    Route::resource('production/husk-chipping', HuskChippingController::class)
        ->parameters(['husk-chipping' => 'huskChipping'])
        ->names('husk-chipping')
        ->except(['destroy']);

    Route::patch('production/husk-chipping/{huskChipping}/post', [HuskChippingActionController::class, 'post'])
        ->name('husk-chipping.post');
    Route::patch('production/husk-chipping/{huskChipping}/reverse', [HuskChippingActionController::class, 'reverse'])
        ->name('husk-chipping.reverse');
    Route::patch('production/husk-chipping/{huskChipping}/cancel', [HuskChippingActionController::class, 'cancel'])
        ->name('husk-chipping.cancel');

    Route::resource('production/copra', CopraController::class)
        ->parameters(['copra' => 'copra'])
        ->names('copra')
        ->except(['destroy']);

    Route::patch('production/copra/{copra}/post', [CopraActionController::class, 'post'])
        ->name('copra.post');
    Route::patch('production/copra/{copra}/reverse', [CopraActionController::class, 'reverse'])
        ->name('copra.reverse');
    Route::patch('production/copra/{copra}/cancel', [CopraActionController::class, 'cancel'])
        ->name('copra.cancel');
});
