<?php

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
 * Any authenticated user may view inventory for now; role-based permissions arrive in
 * Phase 8.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inventory/stock', [StockOnHandController::class, 'index'])
        ->name('inventory.stock.index');

    Route::get('inventory/ledger', [StockLedgerController::class, 'index'])
        ->name('inventory.ledger.index');

    Route::get('inventory/lots', [InventoryLotController::class, 'index'])
        ->name('inventory.lots.index');
});
