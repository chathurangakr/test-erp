<?php

use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerStatusController;
use App\Http\Controllers\Suppliers\SupplierController;
use App\Http\Controllers\Suppliers\SupplierStatusController;
use App\Http\Controllers\Warehouses\WarehouseController;
use App\Http\Controllers\Warehouses\WarehouseDefaultController;
use App\Http\Controllers\Warehouses\WarehouseStatusController;
use Illuminate\Support\Facades\Route;

/*
 * Master data: suppliers, customers and warehouses.
 *
 * Any authenticated user may manage master data for now; role-based permissions arrive in
 * a later phase (see docs/DEVELOPMENT_PLAN.md, Phase 8).
 *
 * There are deliberately NO destroy routes — master records are deactivated, never deleted,
 * so that historic transactions referencing them remain intelligible.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('suppliers', SupplierController::class)->except(['destroy']);
    Route::patch('suppliers/{supplier}/status', [SupplierStatusController::class, 'update'])
        ->name('suppliers.status.update');

    Route::resource('customers', CustomerController::class)->except(['destroy']);
    Route::patch('customers/{customer}/status', [CustomerStatusController::class, 'update'])
        ->name('customers.status.update');

    Route::resource('warehouses', WarehouseController::class)->except(['destroy']);
    Route::patch('warehouses/{warehouse}/status', [WarehouseStatusController::class, 'update'])
        ->name('warehouses.status.update');
    Route::patch('warehouses/{warehouse}/default', [WarehouseDefaultController::class, 'update'])
        ->name('warehouses.default.update');
});
