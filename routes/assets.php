<?php

use App\Enums\Permission;
use App\Http\Controllers\Assets\FixedAssetController;
use Illuminate\Support\Facades\Route;

/*
 * Fixed assets — what the factory owns, its maintenance and its sale. Money is recorded in one
 * step and corrected by reversal; only the name, kind and description are edited. There is no
 * destroy route. See docs/FIXED_ASSETS.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('assets', FixedAssetController::class)
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], Permission::AssetsView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::AssetsManage->middleware());
    Route::patch('assets/{asset}/reverse', [FixedAssetController::class, 'reverse'])
        ->name('assets.reverse')
        ->can(Permission::AssetsReverse);
    Route::post('assets/{asset}/sale', [FixedAssetController::class, 'sell'])
        ->name('assets.sell')
        ->can(Permission::AssetsSell);
    Route::patch('assets/{asset}/sale/reverse', [FixedAssetController::class, 'reverseSale'])
        ->name('assets.sale.reverse')
        ->can(Permission::AssetsSell);
    Route::post('assets/{asset}/maintenance', [FixedAssetController::class, 'storeMaintenance'])
        ->name('assets.maintenance.store')
        ->can(Permission::AssetsMaintenanceRecord);
    Route::patch('asset-maintenance/{assetMaintenance}/reverse', [FixedAssetController::class, 'reverseMaintenance'])
        ->name('asset-maintenance.reverse')
        ->can(Permission::AssetsMaintenanceReverse);
});
