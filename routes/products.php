<?php

use App\Enums\Permission;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Products\ProductStatusController;
use Illuminate\Support\Facades\Route;

/*
 * Product master.
 *
 * Viewing needs products.view; creating, editing and deactivating need products.manage.
 * See docs/AUTHORIZATION_RULES.md.
 *
 * There is deliberately NO destroy route — products are deactivated, never deleted.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('products', ProductController::class)
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::ProductsView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::ProductsManage->middleware());

    Route::patch('products/{product}/status', [ProductStatusController::class, 'update'])
        ->name('products.status.update')
        ->can(Permission::ProductsManage);
});
