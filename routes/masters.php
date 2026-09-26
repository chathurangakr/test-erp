<?php

use App\Enums\Permission;
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
 * Each master has a .view and a .manage permission; status changes and the default
 * warehouse count as managing. See docs/AUTHORIZATION_RULES.md.
 *
 * There are deliberately NO destroy routes — master records are deactivated, never deleted,
 * so that historic transactions referencing them remain intelligible.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('suppliers', SupplierController::class)
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::SuppliersView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::SuppliersManage->middleware());
    Route::patch('suppliers/{supplier}/status', [SupplierStatusController::class, 'update'])
        ->name('suppliers.status.update')
        ->can(Permission::SuppliersManage);

    Route::resource('customers', CustomerController::class)
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::CustomersView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::CustomersManage->middleware());
    Route::patch('customers/{customer}/status', [CustomerStatusController::class, 'update'])
        ->name('customers.status.update')
        ->can(Permission::CustomersManage);

    Route::resource('warehouses', WarehouseController::class)
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::WarehousesView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::WarehousesManage->middleware());
    Route::patch('warehouses/{warehouse}/status', [WarehouseStatusController::class, 'update'])
        ->name('warehouses.status.update')
        ->can(Permission::WarehousesManage);
    Route::patch('warehouses/{warehouse}/default', [WarehouseDefaultController::class, 'update'])
        ->name('warehouses.default.update')
        ->can(Permission::WarehousesManage);
});
