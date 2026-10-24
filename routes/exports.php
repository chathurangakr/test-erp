<?php

use App\Enums\Permission;
use App\Http\Controllers\Reports\ReportExportController;
use Illuminate\Support\Facades\Route;

/*
 * Report exports — PDF or Excel (?format=pdf|xlsx) of a report with its screen's filters. Each
 * export sits beside its report's address and declares the SAME permission as the report, so
 * whoever may see a report may download it and nobody else. GET only: exports never write.
 * See docs/REPORT_EXPORTS.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    $exports = [
        'reports.profit-loss.export' => ['finance/reports/profit-loss/export', 'profit-loss', Permission::ReportsFinance],
        'reports.balance-sheet.export' => ['finance/reports/balance-sheet/export', 'balance-sheet', Permission::ReportsFinance],
        'reports.cash-bank-position.export' => ['finance/reports/cash-bank-position/export', 'cash-bank-position', Permission::ReportsFinance],
        'reports.inventory-valuation.export' => ['finance/reports/inventory-valuation/export', 'inventory-valuation', Permission::ReportsInventory],
        'reports.inventory-gl-reconciliation.export' => ['finance/reports/inventory-gl-reconciliation/export', 'inventory-gl-reconciliation', Permission::ReportsInventory],
        'reports.product-profitability.export' => ['finance/reports/product-profitability/export', 'product-profitability', Permission::ReportsManagement],
        'trial-balance.export' => ['finance/trial-balance/export', 'trial-balance', Permission::FinanceView],
        'general-ledger.export' => ['finance/general-ledger/export', 'general-ledger', Permission::FinanceView],
        'cash-bank-book.export' => ['finance/cash-bank-book/export', 'cash-bank-book', Permission::CashView],
        'ar-aging.export' => ['finance/ar-aging/export', 'ar-aging', Permission::ArView],
        'unbilled-deliveries.export' => ['finance/unbilled-deliveries/export', 'unbilled-deliveries', Permission::ArView],
        'ap-aging.export' => ['finance/ap-aging/export', 'ap-aging', Permission::ApView],
        'grni-reconciliation.export' => ['finance/grni-reconciliation/export', 'grni-reconciliation', Permission::ApView],
        'inventory.stock.export' => ['inventory/stock/export', 'stock-on-hand', Permission::InventoryView],
    ];

    foreach ($exports as $name => [$uri, $report, $permission]) {
        Route::get($uri, ReportExportController::class)
            ->defaults('report', $report)
            ->middleware('throttle:30,1,report-export')
            ->name($name)
            ->can($permission);
    }
});
