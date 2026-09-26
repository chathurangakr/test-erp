<?php

use App\Enums\Permission;
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
 * Drafting, approving, posting, reversing and cancelling are separate permissions.
 * See docs/AUTHORIZATION_RULES.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('sales-orders', SalesOrderController::class)
        ->parameters(['sales-orders' => 'order'])
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::SalesView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::SalesOrderCreate->middleware());

    Route::patch('sales-orders/{order}/approve', [SalesOrderApprovalController::class, 'approve'])
        ->name('sales-orders.approve')
        ->can(Permission::SalesOrderApprove);
    Route::patch('sales-orders/{order}/cancel', [SalesOrderApprovalController::class, 'cancel'])
        ->name('sales-orders.cancel')
        ->can(Permission::SalesOrderCancel);

    Route::resource('deliveries', DeliveryController::class)
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::SalesView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::SalesDeliveryCreate->middleware());

    Route::patch('deliveries/{delivery}/post', [DeliveryActionController::class, 'post'])
        ->name('deliveries.post')
        ->can(Permission::SalesDeliveryPost);
    Route::patch('deliveries/{delivery}/reverse', [DeliveryActionController::class, 'reverse'])
        ->name('deliveries.reverse')
        ->can(Permission::SalesDeliveryReverse);
    Route::patch('deliveries/{delivery}/cancel', [DeliveryActionController::class, 'cancel'])
        ->name('deliveries.cancel')
        ->can(Permission::SalesDeliveryCancel);
});
