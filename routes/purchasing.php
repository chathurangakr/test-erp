<?php

use App\Http\Controllers\Purchasing\GoodsReceiptActionController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PurchaseOrderApprovalController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Purchasing: purchase orders and goods receipts.
 *
 * There are deliberately NO destroy routes. A draft may be edited; an approved order and a
 * posted receipt are controlled documents, corrected by cancellation or reversal.
 *
 * Any authenticated user may use purchasing for now; role-based permissions arrive in
 * Phase 8.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('purchase-orders', PurchaseOrderController::class)
        ->parameters(['purchase-orders' => 'order'])
        ->except(['destroy']);

    Route::patch('purchase-orders/{order}/approve', [PurchaseOrderApprovalController::class, 'approve'])
        ->name('purchase-orders.approve');
    Route::patch('purchase-orders/{order}/cancel', [PurchaseOrderApprovalController::class, 'cancel'])
        ->name('purchase-orders.cancel');

    Route::resource('goods-receipts', GoodsReceiptController::class)
        ->parameters(['goods-receipts' => 'goodsReceipt'])
        ->except(['destroy']);

    Route::patch('goods-receipts/{goodsReceipt}/post', [GoodsReceiptActionController::class, 'post'])
        ->name('goods-receipts.post');
    Route::patch('goods-receipts/{goodsReceipt}/reverse', [GoodsReceiptActionController::class, 'reverse'])
        ->name('goods-receipts.reverse');
    Route::patch('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptActionController::class, 'cancel'])
        ->name('goods-receipts.cancel');
});
