<?php

use App\Enums\Permission;
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
 * Drafting, approving, posting, reversing and cancelling are separate permissions, so a
 * clerk may raise an order that only a manager can approve. See docs/AUTHORIZATION_RULES.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('purchase-orders', PurchaseOrderController::class)
        ->parameters(['purchase-orders' => 'order'])
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::PurchasingView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::PurchasingOrderCreate->middleware());

    Route::patch('purchase-orders/{order}/approve', [PurchaseOrderApprovalController::class, 'approve'])
        ->name('purchase-orders.approve')
        ->can(Permission::PurchasingOrderApprove);
    Route::patch('purchase-orders/{order}/cancel', [PurchaseOrderApprovalController::class, 'cancel'])
        ->name('purchase-orders.cancel')
        ->can(Permission::PurchasingOrderCancel);

    Route::resource('goods-receipts', GoodsReceiptController::class)
        ->parameters(['goods-receipts' => 'goodsReceipt'])
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::PurchasingView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::PurchasingGrnCreate->middleware());

    Route::patch('goods-receipts/{goodsReceipt}/post', [GoodsReceiptActionController::class, 'post'])
        ->name('goods-receipts.post')
        ->can(Permission::PurchasingGrnPost);
    Route::patch('goods-receipts/{goodsReceipt}/reverse', [GoodsReceiptActionController::class, 'reverse'])
        ->name('goods-receipts.reverse')
        ->can(Permission::PurchasingGrnReverse);
    Route::patch('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptActionController::class, 'cancel'])
        ->name('goods-receipts.cancel')
        ->can(Permission::PurchasingGrnCancel);
});
