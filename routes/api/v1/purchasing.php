<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Api\V1\Purchasing\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Purchasing. Each route checks the same permission as its web counterpart
 * (routes/purchasing.php). Route parameter names match the web routes so the shared form
 * requests resolve them identically. No delete routes — correct by cancel or reverse.
 */
Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index')->can(Permission::PurchasingView);
Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store')->can(Permission::PurchasingOrderCreate);
Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show')->can(Permission::PurchasingView);
Route::put('purchase-orders/{order}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update')->can(Permission::PurchasingOrderCreate);
Route::post('purchase-orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve')->can(Permission::PurchasingOrderApprove);
Route::post('purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel')->can(Permission::PurchasingOrderCancel);

Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index')->can(Permission::PurchasingView);
Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store')->can(Permission::PurchasingGrnCreate);
// Offline entry from the phone: save + post in one step, once per Idempotency-Key. Needs the
// post permission here and the create permission in the controller, like simple mode.
Route::post('goods-receipts/record', [GoodsReceiptController::class, 'record'])->name('goods-receipts.record')->can(Permission::PurchasingGrnPost);
Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->name('goods-receipts.show')->can(Permission::PurchasingView);
Route::put('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'update'])->name('goods-receipts.update')->can(Permission::PurchasingGrnCreate);
Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post')->can(Permission::PurchasingGrnPost);
Route::post('goods-receipts/{goodsReceipt}/reverse', [GoodsReceiptController::class, 'reverse'])->name('goods-receipts.reverse')->can(Permission::PurchasingGrnReverse);
Route::post('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('goods-receipts.cancel')->can(Permission::PurchasingGrnCancel);
