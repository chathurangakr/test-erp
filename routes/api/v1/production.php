<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Production\CopraController;
use App\Http\Controllers\Api\V1\Production\DehuskingController;
use App\Http\Controllers\Api\V1\Production\HuskChippingController;
use Illuminate\Support\Facades\Route;

/*
 * Production — dehusking, husk chipping and copra. Each route checks the same permission as its
 * web counterpart (routes/production.php); one permission set covers all three types, because
 * they share one engine. Route parameter names match the web routes so the shared form requests
 * and bindings resolve identically. No delete routes — a draft is cancelled, a posted run reversed.
 */
Route::get('production/dehusking', [DehuskingController::class, 'index'])->name('dehusking.index')->can(Permission::ProductionView);
Route::post('production/dehusking', [DehuskingController::class, 'store'])->name('dehusking.store')->can(Permission::ProductionCreate);
Route::get('production/dehusking/{dehusking}', [DehuskingController::class, 'show'])->name('dehusking.show')->can(Permission::ProductionView);
Route::put('production/dehusking/{dehusking}', [DehuskingController::class, 'update'])->name('dehusking.update')->can(Permission::ProductionCreate);
Route::post('production/dehusking/{dehusking}/post', [DehuskingController::class, 'post'])->name('dehusking.post')->can(Permission::ProductionPost);
Route::post('production/dehusking/{dehusking}/reverse', [DehuskingController::class, 'reverse'])->name('dehusking.reverse')->can(Permission::ProductionReverse);
Route::post('production/dehusking/{dehusking}/cancel', [DehuskingController::class, 'cancel'])->name('dehusking.cancel')->can(Permission::ProductionCancel);

Route::get('production/husk-chipping', [HuskChippingController::class, 'index'])->name('husk-chipping.index')->can(Permission::ProductionView);
Route::post('production/husk-chipping', [HuskChippingController::class, 'store'])->name('husk-chipping.store')->can(Permission::ProductionCreate);
Route::get('production/husk-chipping/{huskChipping}', [HuskChippingController::class, 'show'])->name('husk-chipping.show')->can(Permission::ProductionView);
Route::put('production/husk-chipping/{huskChipping}', [HuskChippingController::class, 'update'])->name('husk-chipping.update')->can(Permission::ProductionCreate);
Route::post('production/husk-chipping/{huskChipping}/post', [HuskChippingController::class, 'post'])->name('husk-chipping.post')->can(Permission::ProductionPost);
Route::post('production/husk-chipping/{huskChipping}/reverse', [HuskChippingController::class, 'reverse'])->name('husk-chipping.reverse')->can(Permission::ProductionReverse);
Route::post('production/husk-chipping/{huskChipping}/cancel', [HuskChippingController::class, 'cancel'])->name('husk-chipping.cancel')->can(Permission::ProductionCancel);

Route::get('production/copra', [CopraController::class, 'index'])->name('copra.index')->can(Permission::ProductionView);
Route::post('production/copra', [CopraController::class, 'store'])->name('copra.store')->can(Permission::ProductionCreate);
Route::get('production/copra/{copra}', [CopraController::class, 'show'])->name('copra.show')->can(Permission::ProductionView);
Route::put('production/copra/{copra}', [CopraController::class, 'update'])->name('copra.update')->can(Permission::ProductionCreate);
Route::post('production/copra/{copra}/post', [CopraController::class, 'post'])->name('copra.post')->can(Permission::ProductionPost);
Route::post('production/copra/{copra}/reverse', [CopraController::class, 'reverse'])->name('copra.reverse')->can(Permission::ProductionReverse);
Route::post('production/copra/{copra}/cancel', [CopraController::class, 'cancel'])->name('copra.cancel')->can(Permission::ProductionCancel);
