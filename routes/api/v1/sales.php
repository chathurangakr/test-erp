<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Sales\DeliveryController;
use App\Http\Controllers\Api\V1\Sales\SalesOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Sales. Each route checks the same permission as its web counterpart (routes/sales.php).
 * Route parameter names match the web routes so the shared form requests resolve them
 * identically. No delete routes — correct by cancel or reverse.
 */
Route::get('sales-orders', [SalesOrderController::class, 'index'])->name('sales-orders.index')->can(Permission::SalesView);
Route::post('sales-orders', [SalesOrderController::class, 'store'])->name('sales-orders.store')->can(Permission::SalesOrderCreate);
Route::get('sales-orders/{order}', [SalesOrderController::class, 'show'])->name('sales-orders.show')->can(Permission::SalesView);
Route::put('sales-orders/{order}', [SalesOrderController::class, 'update'])->name('sales-orders.update')->can(Permission::SalesOrderCreate);
Route::post('sales-orders/{order}/approve', [SalesOrderController::class, 'approve'])->name('sales-orders.approve')->can(Permission::SalesOrderApprove);
Route::post('sales-orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->name('sales-orders.cancel')->can(Permission::SalesOrderCancel);

Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index')->can(Permission::SalesView);
Route::post('deliveries', [DeliveryController::class, 'store'])->name('deliveries.store')->can(Permission::SalesDeliveryCreate);
Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show')->can(Permission::SalesView);
Route::put('deliveries/{delivery}', [DeliveryController::class, 'update'])->name('deliveries.update')->can(Permission::SalesDeliveryCreate);
Route::post('deliveries/{delivery}/post', [DeliveryController::class, 'post'])->name('deliveries.post')->can(Permission::SalesDeliveryPost);
Route::post('deliveries/{delivery}/reverse', [DeliveryController::class, 'reverse'])->name('deliveries.reverse')->can(Permission::SalesDeliveryReverse);
Route::post('deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel'])->name('deliveries.cancel')->can(Permission::SalesDeliveryCancel);
