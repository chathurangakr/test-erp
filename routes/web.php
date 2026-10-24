<?php

use App\Http\Controllers\Administration\CompanyProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

// The bare address (and the Ubuntu app-menu icon) opens the ERP: the dashboard, or sign-in first.
Route::redirect('/', '/dashboard')->name('home');

// The language switch — open to guests so the sign-in screen can be switched too.
Route::post('locale', [LocaleController::class, 'update'])->middleware('throttle:30,1')->name('locale.update');

// The company logo — open to guests so the sign-in screen can show it. It is what the company
// prints on its own invoices, not business data.
Route::get('company/logo', [CompanyProfileController::class, 'logo'])->name('company.logo');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/products.php';
require __DIR__.'/masters.php';
require __DIR__.'/inventory.php';
require __DIR__.'/purchasing.php';
require __DIR__.'/sales.php';
require __DIR__.'/production.php';
require __DIR__.'/finance.php';
require __DIR__.'/payroll.php';
require __DIR__.'/loans.php';
require __DIR__.'/assets.php';
require __DIR__.'/administration.php';
require __DIR__.'/kiosk.php';
require __DIR__.'/settings.php';
