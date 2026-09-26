<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Inventory\LedgerController;
use App\Http\Controllers\Api\V1\Inventory\LotController;
use App\Http\Controllers\Api\V1\Inventory\StockController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory — READ ONLY, like the web (routes/inventory.php). Stock is written only by
 * business documents through PostInventoryTransaction; there is no adjustment endpoint.
 */
Route::get('inventory/stock', [StockController::class, 'index'])->name('inventory.stock.index')->can(Permission::InventoryView);
Route::get('inventory/ledger', [LedgerController::class, 'index'])->name('inventory.ledger.index')->can(Permission::InventoryView);
Route::get('inventory/lots', [LotController::class, 'index'])->name('inventory.lots.index')->can(Permission::InventoryView);
