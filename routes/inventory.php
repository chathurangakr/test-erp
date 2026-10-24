<?php

use App\Enums\Permission;
use App\Http\Controllers\Inventory\InventoryLotController;
use App\Http\Controllers\Inventory\StockDamageController;
use App\Http\Controllers\Inventory\StockLedgerController;
use App\Http\Controllers\Inventory\StockOnHandController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory — read-only screens, plus damaged stock write-offs.
 *
 * There are deliberately no edit or delete routes. Inventory is written only through
 * App\Actions\Inventory\PostInventoryTransaction, which business documents call. Posted
 * transactions are immutable (docs/INVENTORY_RULES.md §9). A stock damage record is itself a
 * business document: written off in one step, corrected by reversal (§17).
 *
 * Every screen needs inventory.view. There is no transfer screen, so no transfer
 * permission exists yet.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inventory/stock', [StockOnHandController::class, 'index'])
        ->name('inventory.stock.index')
        ->can(Permission::InventoryView);

    Route::get('inventory/ledger', [StockLedgerController::class, 'index'])
        ->name('inventory.ledger.index')
        ->can(Permission::InventoryView);

    Route::get('inventory/lots', [InventoryLotController::class, 'index'])
        ->name('inventory.lots.index')
        ->can(Permission::InventoryView);

    Route::resource('inventory/damages', StockDamageController::class)
        ->parameters(['damages' => 'stockDamage'])
        ->names('stock-damages')
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], Permission::InventoryView->middleware())
        ->middlewareFor(['create', 'store'], Permission::InventoryDamageRecord->middleware());
    Route::patch('inventory/damages/{stockDamage}/reverse', [StockDamageController::class, 'reverse'])
        ->name('stock-damages.reverse')
        ->can(Permission::InventoryDamageReverse);
});
