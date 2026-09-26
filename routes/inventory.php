<?php

use App\Enums\Permission;
use App\Http\Controllers\Inventory\InventoryLotController;
use App\Http\Controllers\Inventory\StockLedgerController;
use App\Http\Controllers\Inventory\StockOnHandController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory — READ ONLY.
 *
 * There are deliberately no posting, edit or delete routes. Inventory is written only
 * through App\Actions\Inventory\PostInventoryTransaction, which business documents call in
 * later phases. Posted transactions are immutable (docs/INVENTORY_RULES.md §9).
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
});
