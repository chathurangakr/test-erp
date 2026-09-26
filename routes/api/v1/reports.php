<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Finance\ReportController;
use Illuminate\Support\Facades\Route;

/*
 * Finance reports — read only. Each returns exactly what the owning service returns, and
 * checks the same permission as the matching web screen, so a user who cannot open the report
 * on the website cannot fetch it here either.
 */
Route::get('reports/finance-dashboard', [ReportController::class, 'financeDashboard'])->name('finance-dashboard.index')->can(Permission::ReportsManagement);
Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->name('reports.profit-loss')->can(Permission::ReportsFinance);
Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet')->can(Permission::ReportsFinance);
Route::get('reports/cash-bank-position', [ReportController::class, 'cashBankPosition'])->name('reports.cash-bank-position')->can(Permission::ReportsFinance);
Route::get('reports/inventory-valuation', [ReportController::class, 'inventoryValuation'])->name('reports.inventory-valuation')->can(Permission::ReportsInventory);
Route::get('reports/inventory-gl-reconciliation', [ReportController::class, 'inventoryGlReconciliation'])->name('reports.inventory-gl-reconciliation')->can(Permission::ReportsInventory);
Route::get('reports/product-profitability', [ReportController::class, 'productProfitability'])->name('reports.product-profitability')->can(Permission::ReportsManagement);
Route::get('reports/ar-aging', [ReportController::class, 'arAging'])->name('ar-aging.index')->can(Permission::ArView);
Route::get('reports/unbilled-deliveries', [ReportController::class, 'unbilledDeliveries'])->name('unbilled-deliveries.index')->can(Permission::ArView);
Route::get('reports/ap-aging', [ReportController::class, 'apAging'])->name('ap-aging.index')->can(Permission::ApView);
Route::get('reports/grni-reconciliation', [ReportController::class, 'grniReconciliation'])->name('grni-reconciliation.index')->can(Permission::ApView);
Route::get('reports/cash-bank-book', [ReportController::class, 'cashBankBook'])->name('cash-bank-book.index')->can(Permission::CashView);
