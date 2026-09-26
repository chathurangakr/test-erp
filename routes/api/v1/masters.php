<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Masters\CustomerController;
use App\Http\Controllers\Api\V1\Masters\ProductController;
use App\Http\Controllers\Api\V1\Masters\SupplierController;
use App\Http\Controllers\Api\V1\Masters\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
 * Master data. Each route checks the same permission as its web counterpart (routes/products.php,
 * routes/masters.php). Parameter names match the web routes so the shared form requests
 * resolve them identically. No delete routes: master records are deactivated, never removed.
 * Status changes take the target state explicitly ({"is_active": true|false}), so a retried
 * request cannot flip it back.
 */
Route::get('products', [ProductController::class, 'index'])->name('products.index')->can(Permission::ProductsView);
Route::post('products', [ProductController::class, 'store'])->name('products.store')->can(Permission::ProductsManage);
Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show')->can(Permission::ProductsView);
Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update')->can(Permission::ProductsManage);
Route::post('products/{product}/status', [ProductController::class, 'status'])->name('products.status.update')->can(Permission::ProductsManage);

Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index')->can(Permission::SuppliersView);
Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store')->can(Permission::SuppliersManage);
Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show')->can(Permission::SuppliersView);
Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update')->can(Permission::SuppliersManage);
Route::post('suppliers/{supplier}/status', [SupplierController::class, 'status'])->name('suppliers.status.update')->can(Permission::SuppliersManage);

Route::get('customers', [CustomerController::class, 'index'])->name('customers.index')->can(Permission::CustomersView);
Route::post('customers', [CustomerController::class, 'store'])->name('customers.store')->can(Permission::CustomersManage);
Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show')->can(Permission::CustomersView);
Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update')->can(Permission::CustomersManage);
Route::post('customers/{customer}/status', [CustomerController::class, 'status'])->name('customers.status.update')->can(Permission::CustomersManage);

Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index')->can(Permission::WarehousesView);
Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store')->can(Permission::WarehousesManage);
Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('warehouses.show')->can(Permission::WarehousesView);
Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update')->can(Permission::WarehousesManage);
Route::post('warehouses/{warehouse}/status', [WarehouseController::class, 'status'])->name('warehouses.status.update')->can(Permission::WarehousesManage);
Route::post('warehouses/{warehouse}/default', [WarehouseController::class, 'makeDefault'])->name('warehouses.default.update')->can(Permission::WarehousesManage);
