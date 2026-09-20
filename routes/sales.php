<?php

use App\Http\Controllers\Sales\DeliveryActionController;
use App\Http\Controllers\Sales\DeliveryController;
use App\Http\Controllers\Sales\SalesOrderApprovalController;
use App\Http\Controllers\Sales\SalesOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Sales: sales orders and deliveries.
 *
 * There are deliberately NO destroy routes. A draft may be edited or cancelled; an
 * approved order and a posted delivery are controlled documents, corrected by cancellation
 * or reversal.
 *
 * Any authenticated user may use sales for now; role-based permissions arrive in Phase 8.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('sales-orders', SalesOrderController::class)
        ->parameters(['sales-orders' => 'order'])
        ->except(['destroy']);

    Route::patch('sales-orders/{order}/approve', [SalesOrderApprovalController::class, 'approve'])
        ->name('sales-orders.approve');
    Route::patch('sales-orders/{order}/cancel', [SalesOrderApprovalController::class, 'cancel'])
        ->name('sales-orders.cancel');

    Route::resource('deliveries', DeliveryController::class)->except(['destroy']);

    Route::patch('deliveries/{delivery}/post', [DeliveryActionController::class, 'post'])
        ->name('deliveries.post');
    Route::patch('deliveries/{delivery}/reverse', [DeliveryActionController::class, 'reverse'])
        ->name('deliveries.reverse');
    Route::patch('deliveries/{delivery}/cancel', [DeliveryActionController::class, 'cancel'])
        ->name('deliveries.cancel');
});
