<?php

use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Products\ProductStatusController;
use Illuminate\Support\Facades\Route;

/*
 * Product master.
 *
 * Any authenticated user may manage products for now; role-based permissions arrive in a
 * later phase (see docs/DEVELOPMENT_PLAN.md, Phase 8).
 *
 * There is deliberately NO destroy route — products are deactivated, never deleted.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('products', ProductController::class)->except(['destroy']);

    Route::patch('products/{product}/status', [ProductStatusController::class, 'update'])
        ->name('products.status.update');
});
