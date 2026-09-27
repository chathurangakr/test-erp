<?php

use App\Enums\Permission;
use App\Http\Controllers\Kiosk\KioskController;
use App\Http\Controllers\Kiosk\KioskEntryController;
use Illuminate\Support\Facades\Route;

/*
 * Simple mode — big-button screens for factory staff. See docs/KIOSK.md.
 *
 * Every job route requires that job's POST permission (Done saves and posts at once); the store
 * actions additionally require the CREATE permission. Result pages need only the view
 * permission of their document. The home screen and the language switch are open to any
 * signed-in user; home draws only the tiles their roles allow.
 */
Route::middleware(['auth', 'verified', 'kiosk.locale'])->prefix('kiosk')->name('kiosk.')->group(function () {
    Route::get('/', [KioskController::class, 'home'])->name('home');
    Route::post('language', [KioskController::class, 'language'])->name('language');
    Route::get('stock', [KioskController::class, 'stock'])->name('stock')->can(Permission::InventoryView);

    Route::get('receive', [KioskEntryController::class, 'receive'])->name('receive')->can(Permission::PurchasingGrnPost);
    Route::post('receive', [KioskEntryController::class, 'storeReceive'])->name('receive.store')->can(Permission::PurchasingGrnPost);
    Route::get('receive/{goodsReceipt}/done', [KioskEntryController::class, 'receiveDone'])->name('receive.done')->can(Permission::PurchasingView);

    Route::get('dispatch', [KioskEntryController::class, 'dispatch'])->name('dispatch')->can(Permission::SalesDeliveryPost);
    Route::post('dispatch', [KioskEntryController::class, 'storeDispatch'])->name('dispatch.store')->can(Permission::SalesDeliveryPost);
    Route::get('dispatch/{delivery}/done', [KioskEntryController::class, 'dispatchDone'])->name('dispatch.done')->can(Permission::SalesView);

    Route::get('dehusking', [KioskEntryController::class, 'dehusking'])->name('dehusking')->can(Permission::ProductionPost);
    Route::post('dehusking', [KioskEntryController::class, 'storeDehusking'])->name('dehusking.store')->can(Permission::ProductionPost);
    Route::get('dehusking/{dehusking}/done', [KioskEntryController::class, 'dehuskingDone'])->name('dehusking.done')->can(Permission::ProductionView);

    Route::get('husk-chipping', [KioskEntryController::class, 'huskChipping'])->name('husk-chipping')->can(Permission::ProductionPost);
    Route::post('husk-chipping', [KioskEntryController::class, 'storeHuskChipping'])->name('husk-chipping.store')->can(Permission::ProductionPost);
    Route::get('husk-chipping/{huskChipping}/done', [KioskEntryController::class, 'huskChippingDone'])->name('husk-chipping.done')->can(Permission::ProductionView);

    Route::get('copra', [KioskEntryController::class, 'copra'])->name('copra')->can(Permission::ProductionPost);
    Route::post('copra', [KioskEntryController::class, 'storeCopra'])->name('copra.store')->can(Permission::ProductionPost);
    Route::get('copra/{copra}/done', [KioskEntryController::class, 'copraDone'])->name('copra.done')->can(Permission::ProductionView);
});
